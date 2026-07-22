<?php

namespace App\Services;

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Memproses penyusutan garis lurus SECARA MANUAL, dipicu tombol per
 * periode -- bukan cron/scheduler otomatis. Lihat dokumen rancangan:
 * project ini tidak punya infrastruktur scheduler sama sekali, dan
 * pemrosesan manual (dengan pratinjau dulu) konsisten dengan pola
 * konfirmasi-sebelum-posting yang dipakai di semua fitur keuangan lain.
 *
 * Aman diklik berulang: previewForPeriod()/processForPeriod() melewati
 * (skip) aset yang sudah punya depreciation_entries untuk periode itu --
 * dijamin ganda oleh unique index (fixed_asset_id, period) di database
 * sebagai jaring pengaman terakhir.
 */
class DepreciationService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by the 2026_07_22_170000 migration.
    private const ACCOUNT_BEBAN_PENYUSUTAN = '5-4000';

    private const ACCOUNT_AKUMULASI_PENYUSUTAN = '1-2900';

    public function __construct(
        private readonly PostingService $posting,
        private readonly FixedAssetService $fixedAssets,
    ) {}

    /**
     * Aset yang eligible untuk periode ini + jumlah yang akan diposting,
     * TANPA menulis apa pun -- dipakai untuk pratinjau di form sebelum
     * admin konfirmasi. Aset yang sudah habis disusutkan (nilai buku =
     * residu) atau sudah punya entri untuk periode ini tidak muncul sama
     * sekali (bukan muncul dengan jumlah nol).
     *
     * @return array<int, array{asset: FixedAsset, amount: string}>
     */
    public function previewForPeriod(string $period): array
    {
        $this->assertValidPeriod($period);

        $assets = FixedAsset::whereDoesntHave(
            'depreciationEntries',
            fn ($query) => $query->where('period', $period),
        )->orderBy('purchase_date')->orderBy('id')->get();

        $rows = [];

        foreach ($assets as $asset) {
            $remaining = $this->fixedAssets->remainingDepreciable($asset);

            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                continue; // sudah habis disusutkan -- berhenti selamanya
            }

            $monthly = $this->fixedAssets->monthlyDepreciationAmount($asset);
            $amount = bccomp($monthly, $remaining, self::SCALE) > 0 ? $remaining : $monthly;

            $rows[] = ['asset' => $asset, 'amount' => $amount];
        }

        return $rows;
    }

    /**
     * Benar-benar posting penyusutan untuk $period -- satu
     * DepreciationEntry + satu jurnal (Dr Beban Penyusutan / Cr Akumulasi
     * Penyusutan) per aset eligible, semuanya dalam SATU DB::transaction
     * (kalau satu aset gagal, semuanya batal -- prinsip #5).
     *
     * @return array<int, DepreciationEntry>
     */
    public function processForPeriod(string $period, DateTimeInterface|string $date, ?int $createdByUserId = null): array
    {
        $this->assertValidPeriod($period);

        return DB::transaction(function () use ($period, $date, $createdByUserId) {
            $entries = [];

            foreach ($this->previewForPeriod($period) as $row) {
                $asset = $row['asset'];
                $amount = $row['amount'];

                $entry = new DepreciationEntry([
                    'fixed_asset_id' => $asset->id,
                    'period' => $period,
                    'date' => $date,
                    'amount' => $amount,
                    'created_by_user_id' => $createdByUserId,
                ]);
                $entry->save();

                $this->posting->post(
                    lines: [
                        ['account' => self::ACCOUNT_BEBAN_PENYUSUTAN, 'debit' => $amount, 'credit' => 0],
                        ['account' => self::ACCOUNT_AKUMULASI_PENYUSUTAN, 'debit' => 0, 'credit' => $amount],
                    ],
                    date: $date,
                    source: $entry,
                    memo: "Penyusutan {$asset->name} periode {$period}",
                );

                $entries[] = $entry->fresh();
            }

            return $entries;
        });
    }

    /**
     * @throws InvalidArgumentException if $period isn't 'YYYY-MM'.
     */
    private function assertValidPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Format periode tidak valid: [{$period}]. Harus \"YYYY-MM\".");
        }
    }
}
