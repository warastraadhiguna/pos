<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\FixedAssetPayment;
use App\Models\JournalLine;
use DateTimeInterface;

/**
 * Outstanding hutang aset tetap per catatan `fixed_assets` kredit. Mirror
 * ExpensePayableReportService persis: total hutang (`asset_total`)
 * dihitung LIVE dari journal_lines akun 2-9000 (bukan kolom cache apa
 * pun), "sudah dibayar" dari fixed_asset_payments langsung (bukan tabel
 * alokasi -- satu FixedAssetPayment selalu menunjuk satu fixed_asset_id).
 */
class FixedAssetPayableReportService
{
    private const SCALE = 4;

    private const ACCOUNT_HUTANG_LAIN_LAIN = '2-9000';

    /**
     * Semua aset kredit yang belum lunas, TERTUA DULU. Aset tunai tidak
     * pernah muncul di sini.
     *
     * @return array<int, array{
     *     fixed_asset_id: int, name: string, purchase_date: string,
     *     asset_total: string, paid: string, remaining: string, status: string,
     * }>
     */
    public function unpaidAssets(DateTimeInterface|string|null $asOfDate = null): array
    {
        $hutangAccountId = Account::where('code', self::ACCOUNT_HUTANG_LAIN_LAIN)->firstOrFail()->id;

        $assets = FixedAsset::query()
            ->where('payment_method', 'credit')
            ->when($asOfDate, fn ($query) => $query->where('purchase_date', '<=', $asOfDate))
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get();

        return $assets
            ->map(fn (FixedAsset $asset) => $this->assetStatus($asset, $hutangAccountId))
            ->filter(fn (array $row) => bccomp($row['remaining'], '0', self::SCALE) > 0)
            ->values()
            ->all();
    }

    /**
     * Status satu aset. $hutangAccountId boleh dilewatkan oleh pemanggil
     * beruntun (unpaidAssets) supaya tidak query akun berulang.
     *
     * @return array{
     *     fixed_asset_id: int, name: string, purchase_date: string,
     *     asset_total: string, paid: string, remaining: string, status: string,
     * }
     */
    public function assetStatus(FixedAsset $asset, ?int $hutangAccountId = null): array
    {
        if ($asset->payment_method !== 'credit') {
            return [
                'fixed_asset_id' => $asset->id,
                'name' => $asset->name,
                'purchase_date' => (string) $asset->purchase_date,
                'asset_total' => '0.0000',
                'paid' => '0.0000',
                'remaining' => '0.0000',
                'status' => 'tunai',
            ];
        }

        $hutangAccountId ??= Account::where('code', self::ACCOUNT_HUTANG_LAIN_LAIN)->firstOrFail()->id;

        $assetTotal = bcadd((string) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.source_type', FixedAsset::class)
            ->where('journals.source_id', $asset->id)
            ->where('journal_lines.account_id', $hutangAccountId)
            ->sum('journal_lines.credit'), '0', self::SCALE);

        $paid = bcadd((string) FixedAssetPayment::where('fixed_asset_id', $asset->id)->sum('amount'), '0', self::SCALE);

        $remaining = bcsub($assetTotal, $paid, self::SCALE);

        $status = match (true) {
            bccomp($remaining, '0', self::SCALE) <= 0 => 'lunas',
            bccomp($paid, '0', self::SCALE) > 0 => 'sebagian',
            default => 'belum',
        };

        return [
            'fixed_asset_id' => $asset->id,
            'name' => $asset->name,
            'purchase_date' => (string) $asset->purchase_date,
            'asset_total' => $assetTotal,
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => $status,
        ];
    }
}
