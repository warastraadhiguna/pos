<?php

namespace App\Services;

use App\Models\FixedAsset;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FixedAssetService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by the 2026_07_22_170000 migration.
    private const ACCOUNT_ASET_TETAP = '1-2000';

    private const ACCOUNT_HUTANG_LAIN_LAIN = '2-9000';

    public function __construct(
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Catat pembelian aset tetap -- Dr Aset Tetap, Cr {cash_account_code}
     * (tunai) atau Cr Hutang Lain-lain (kredit). Persis pola
     * PurchaseService/ExpenseService: sisi debit tetap, hanya akun kredit
     * yang bercabang berdasarkan payment_method.
     *
     * @param  array{
     *     outlet_id: int,
     *     name: string,
     *     category?: ?string,
     *     purchase_date: DateTimeInterface|string,
     *     acquisition_cost: int|float|string,
     *     residual_value?: int|float|string,
     *     useful_life_months: int,
     *     payment_method: string,
     *     cash_account_code?: ?string,
     *     created_by_user_id?: ?int,
     * }  $data
     *
     * @throws InvalidArgumentException if the cost/residual/useful life is invalid, or the cash account is invalid.
     */
    public function recordPurchase(array $data): FixedAsset
    {
        $cost = (string) $data['acquisition_cost'];
        if (bccomp($cost, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Harga perolehan harus lebih besar dari nol.');
        }

        $residual = (string) ($data['residual_value'] ?? '0');
        if (bccomp($residual, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Nilai residu tidak boleh negatif.');
        }
        if (bccomp($residual, $cost, self::SCALE) >= 0) {
            throw new InvalidArgumentException('Nilai residu harus lebih kecil dari harga perolehan.');
        }

        $usefulLifeMonths = (int) $data['useful_life_months'];
        if ($usefulLifeMonths <= 0) {
            throw new InvalidArgumentException('Masa manfaat harus lebih besar dari nol bulan.');
        }

        $cashAccountCode = $data['cash_account_code'] ?? CashAccountService::DEFAULT_CODE;

        if ($data['payment_method'] === 'cash') {
            $this->cashAccounts->assertValidCashAccount($cashAccountCode);
        }

        return DB::transaction(function () use ($data, $cost, $residual, $usefulLifeMonths, $cashAccountCode) {
            $asset = new FixedAsset([
                'outlet_id' => $data['outlet_id'],
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'acquisition_cost' => $cost,
                'residual_value' => $residual,
                'useful_life_months' => $usefulLifeMonths,
                'depreciation_method' => 'straight_line',
                'payment_method' => $data['payment_method'],
                'cash_account_code' => $cashAccountCode,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);
            $asset->save();

            $creditAccount = match ($data['payment_method']) {
                'cash' => $cashAccountCode,
                'credit' => self::ACCOUNT_HUTANG_LAIN_LAIN,
                default => throw new InvalidArgumentException("Unknown payment method [{$data['payment_method']}]."),
            };

            $this->posting->post(
                lines: [
                    ['account' => self::ACCOUNT_ASET_TETAP, 'debit' => $cost, 'credit' => 0],
                    ['account' => $creditAccount, 'debit' => 0, 'credit' => $cost],
                ],
                date: $data['purchase_date'],
                source: $asset,
                memo: "Pembelian aset: {$asset->name}",
            );

            return $asset->fresh();
        });
    }

    /**
     * Total penyusutan yang sudah tercatat untuk aset ini -- dihitung LIVE
     * dari depreciation_entries, tidak pernah di-cache (disiplin sama
     * seperti stok/hutang di seluruh sistem ini).
     */
    public function accumulatedDepreciation(FixedAsset $asset): string
    {
        return bcadd((string) $asset->depreciationEntries()->sum('amount'), '0', self::SCALE);
    }

    /**
     * Nilai buku = harga perolehan − akumulasi penyusutan.
     */
    public function bookValue(FixedAsset $asset): string
    {
        return bcsub((string) $asset->acquisition_cost, $this->accumulatedDepreciation($asset), self::SCALE);
    }

    /**
     * Sisa yang masih boleh disusutkan sebelum mencapai nilai residu.
     * Begitu ini <= 0, aset TIDAK BOLEH disusutkan lagi (lihat
     * DepreciationService::previewForPeriod()).
     */
    public function remainingDepreciable(FixedAsset $asset): string
    {
        $depreciableBase = bcsub((string) $asset->acquisition_cost, (string) $asset->residual_value, self::SCALE);

        return bcsub($depreciableBase, $this->accumulatedDepreciation($asset), self::SCALE);
    }

    /**
     * Penyusutan garis lurus per bulan: (harga perolehan − residu) ÷ masa
     * manfaat (bulan). Jumlah AKTUAL yang diposting untuk suatu periode
     * bisa lebih kecil dari ini kalau sisa yang bisa disusutkan tidak
     * cukup satu bulan penuh (lihat DepreciationService).
     */
    public function monthlyDepreciationAmount(FixedAsset $asset): string
    {
        $depreciableBase = bcsub((string) $asset->acquisition_cost, (string) $asset->residual_value, self::SCALE);

        return bcdiv($depreciableBase, (string) $asset->useful_life_months, self::SCALE);
    }
}
