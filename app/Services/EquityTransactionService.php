<?php

namespace App\Services;

use App\Models\EquityTransaction;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EquityTransactionService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by the 2026_07_22_150000 migration.
    private const ACCOUNT_MODAL = '3-1000';

    private const ACCOUNT_PRIVE = '3-2000';

    public function __construct(
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Setoran modal pemilik -- Dr {cash_account_code} / Cr Modal Pemilik.
     * Uang MASUK ke Kas/Bank yang dipilih.
     *
     * @param  array{
     *     outlet_id: int,
     *     date: DateTimeInterface|string,
     *     amount: int|float|string,
     *     cash_account_code?: ?string,
     *     description?: ?string,
     *     created_by_user_id?: ?int,
     * }  $data
     *
     * @throws InvalidArgumentException if the cash account is invalid, or amount <= 0.
     */
    public function recordModalDeposit(array $data): EquityTransaction
    {
        return $this->record($data, 'modal', self::ACCOUNT_MODAL, debitsCash: true);
    }

    /**
     * Pengambilan pribadi (Prive) -- Dr Prive / Cr {cash_account_code}.
     * Uang KELUAR dari Kas/Bank yang dipilih. Prive BUKAN beban -- akun
     * Prive bertipe `equity` (kontra-ekuitas), jadi tidak pernah muncul di
     * Laba Rugi (FinancialReportService::incomeStatement() hanya query
     * tipe revenue/expense) -- pemisahan ini struktural lewat tipe akun,
     * bukan aturan/exception manual.
     *
     * @param  array{
     *     outlet_id: int,
     *     date: DateTimeInterface|string,
     *     amount: int|float|string,
     *     cash_account_code?: ?string,
     *     description?: ?string,
     *     created_by_user_id?: ?int,
     * }  $data
     *
     * @throws InvalidArgumentException if the cash account is invalid, or amount <= 0.
     */
    public function recordPriveWithdrawal(array $data): EquityTransaction
    {
        return $this->record($data, 'prive', self::ACCOUNT_PRIVE, debitsCash: false);
    }

    private function record(array $data, string $type, string $equityAccountCode, bool $debitsCash): EquityTransaction
    {
        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Jumlah harus lebih besar dari nol.');
        }

        $cashAccountCode = $data['cash_account_code'] ?? CashAccountService::DEFAULT_CODE;
        $this->cashAccounts->assertValidCashAccount($cashAccountCode);

        return DB::transaction(function () use ($data, $amount, $type, $equityAccountCode, $cashAccountCode, $debitsCash) {
            $transaction = new EquityTransaction([
                'outlet_id' => $data['outlet_id'],
                'date' => $data['date'],
                'type' => $type,
                'amount' => $amount,
                'cash_account_code' => $cashAccountCode,
                'description' => $data['description'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);
            $transaction->save();

            $lines = $debitsCash
                ? [
                    ['account' => $cashAccountCode, 'debit' => $amount, 'credit' => 0],
                    ['account' => $equityAccountCode, 'debit' => 0, 'credit' => $amount],
                ]
                : [
                    ['account' => $equityAccountCode, 'debit' => $amount, 'credit' => 0],
                    ['account' => $cashAccountCode, 'debit' => 0, 'credit' => $amount],
                ];

            $label = $type === 'modal' ? 'Setoran modal pemilik' : 'Pengambilan pribadi (Prive)';
            $description = $data['description'] ?? null;
            $memo = $description ? "{$label}: {$description}" : $label;

            $this->posting->post(
                lines: $lines,
                date: $data['date'],
                source: $transaction,
                memo: $memo,
            );

            return $transaction->fresh();
        });
    }
}
