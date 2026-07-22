<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\FixedAssetPayment;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FixedAssetPaymentService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by the 2026_07_22_170000 migration.
    private const ACCOUNT_HUTANG_LAIN_LAIN = '2-9000';

    public function __construct(
        private readonly PostingService $posting,
        private readonly FixedAssetPayableReportService $payableReport,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Lunasi (boleh parsial) SATU aset tetap kredit -- selalu Dr Hutang
     * Lain-lain / Cr {cash_account_code}, satu jurnal per pembayaran.
     * Mirror ExpensePaymentService::recordPayment() persis.
     *
     * @param  array{outlet_id: int, fixed_asset_id: int, date: DateTimeInterface|string, amount: int|float|string, cash_account_code?: ?string, memo?: ?string}  $data
     *
     * @throws InvalidArgumentException if the asset is cash-paid, the amount is <= 0, the cash account is invalid, or the amount exceeds what's still owed.
     */
    public function recordPayment(array $data): FixedAssetPayment
    {
        $asset = FixedAsset::findOrFail($data['fixed_asset_id']);

        if ($asset->payment_method !== 'credit') {
            throw new InvalidArgumentException('Aset ini dibeli tunai -- tidak ada hutang untuk dilunasi.');
        }

        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Jumlah pembayaran harus lebih besar dari nol.');
        }

        $cashAccountCode = $data['cash_account_code'] ?? CashAccountService::DEFAULT_CODE;
        $this->cashAccounts->assertValidCashAccount($cashAccountCode);

        $status = $this->payableReport->assetStatus($asset);

        if (bccomp($amount, $status['remaining'], self::SCALE) > 0) {
            throw new InvalidArgumentException(
                "Jumlah pembayaran ({$amount}) melebihi sisa hutang aset ini ({$status['remaining']})."
            );
        }

        return DB::transaction(function () use ($data, $asset, $amount, $cashAccountCode) {
            $payment = new FixedAssetPayment([
                'outlet_id' => $data['outlet_id'],
                'fixed_asset_id' => $asset->id,
                'date' => $data['date'],
                'amount' => $amount,
                'cash_account_code' => $cashAccountCode,
                'memo' => $data['memo'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);
            $payment->save();

            $this->posting->post(
                lines: [
                    ['account' => self::ACCOUNT_HUTANG_LAIN_LAIN, 'debit' => $amount, 'credit' => 0],
                    ['account' => $cashAccountCode, 'debit' => 0, 'credit' => $amount],
                ],
                date: $data['date'],
                source: $payment,
                memo: "Pembayaran hutang aset #{$asset->id}: {$asset->name}",
            );

            return $payment->fresh();
        });
    }
}
