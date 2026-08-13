<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockDistribution;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Multi-Cabang Lapisan 2 -- distribusi stok pusat->cabang. Lihat rancangan
 * yang disetujui. Sengaja TIDAK mengandung satu pun pemanggilan
 * `PostingService` -- ini murni pemindahan fisik antar gudang, bukan
 * transaksi akuntansi (poin 4/5 rancangan). Sengaja TIDAK menambah method
 * baru di `InventoryService` -- eksekusi murni komposisi
 * `lockAndReadCurrentStock()` + `recordOutbound()` + `recordInbound()` yang
 * SUDAH ADA (lihat docblock `executeDistribution()` untuk bukti aljabar
 * kenapa komposisi ini otomatis menjaga HPP & nilai persediaan total).
 */
class DistributionService
{
    private const SCALE = 4;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Mencatat INTENT saja -- tidak menyentuh stok/ledger sama sekali,
     * pola sama `PurchaseService::createPurchaseOrder()`.
     *
     * @param  array{
     *     source_warehouse_id: int,
     *     dest_warehouse_id: int,
     *     date: \DateTimeInterface|string,
     *     lines: array<int, array{item_id: int, qty: int|float|string}>,
     *     notes?: ?string,
     *     created_by_user_id?: ?int,
     * }  $data
     *
     * @throws InvalidArgumentException kalau source bukan gudang pusat, source===dest, atau ada item cost_only.
     */
    public function createDistribution(array $data): StockDistribution
    {
        $sourceWarehouse = Warehouse::with('outlet')->findOrFail($data['source_warehouse_id']);

        // Poin 1 rancangan yang disetujui: "Source wajib warehouse pusat" --
        // ditegakkan di sini (Service layer), BUKAN constraint DB, konsisten
        // pola is_headquarters di BranchService.
        if (! $sourceWarehouse->outlet?->is_headquarters) {
            throw new InvalidArgumentException(
                "Gudang asal distribusi harus gudang milik cabang PUSAT -- [{$sourceWarehouse->name}] bukan gudang pusat.",
            );
        }

        if ((int) $data['source_warehouse_id'] === (int) $data['dest_warehouse_id']) {
            throw new InvalidArgumentException('Gudang tujuan harus berbeda dari gudang asal.');
        }

        return DB::transaction(function () use ($data, $sourceWarehouse) {
            $distribution = StockDistribution::create([
                'source_warehouse_id' => $sourceWarehouse->id,
                'dest_warehouse_id' => $data['dest_warehouse_id'],
                'date' => $data['date'],
                'status' => StockDistribution::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);

            foreach ($data['lines'] as $lineData) {
                $item = Item::findOrFail($lineData['item_id']);

                // cost_only (mis. air) tidak pernah punya baris
                // stock_movements sama sekali (lihat InventoryService) --
                // tidak ada apa pun yang bisa dipindah-lokasikan, ditolak
                // eksplisit di sini alih-alih diam-diam dilewati saat
                // eksekusi nanti.
                if ($item->costing_type === 'cost_only') {
                    throw new InvalidArgumentException(
                        "Item [{$item->sku}] bertipe cost_only -- tidak punya stok per-lokasi untuk didistribusikan.",
                    );
                }

                $distribution->lines()->create([
                    'item_id' => $item->id,
                    'qty' => (string) $lineData['qty'],
                ]);
            }

            return $distribution->fresh('lines');
        });
    }

    /**
     * Stok pusat SAAT INI per baris, dibandingkan terhadap qty yang diminta
     * -- pola PERSIS `PurchaseService::detectOverReceipts()`. Kekurangan
     * stok TETAP SAH (konsisten "stok boleh minus" di seluruh aplikasi ini,
     * lihat docs/ROADMAP.md) tapi tidak boleh diam-diam diproses -- caller
     * (controller) wajib meminta konfirmasi eksplisit dulu.
     *
     * @return array<int, array{line: \App\Models\StockDistributionLine, requested: string, available: string}>
     */
    public function detectInsufficientStock(StockDistribution $distribution): array
    {
        $distribution->loadMissing('lines.item', 'sourceWarehouse');
        $shortages = [];

        foreach ($distribution->lines as $line) {
            $available = $this->inventory->currentStock($line->item, $distribution->sourceWarehouse);

            if (bccomp((string) $line->qty, $available, self::SCALE) > 0) {
                $shortages[] = [
                    'line' => $line,
                    'requested' => (string) $line->qty,
                    'available' => $available,
                ];
            }
        }

        return $shortages;
    }

    /**
     * SATU-SATUNYA tempat stok/ledger benar-benar bergerak. Per baris, di
     * dalam SATU DB::transaction() yang membungkus SELURUH dokumen
     * (prinsip #5 PRINCIPLES.md), diurutkan `item_id` ASC (pola sama
     * StockOpnameService) supaya distribusi lain yang berjalan bersamaan
     * mengunci baris item dalam urutan konsisten -- mencegah deadlock:
     *
     * 1. Baca HPP pusat SAAT INI di bawah lock (`lockAndReadCurrentStock()`,
     *    method yang SUDAH ADA, dipakai StockOpnameService::postOpname()).
     * 2. `recordOutbound()` di gudang pusat -- unit_cost movement ini
     *    OTOMATIS = HPP yang baru dibaca (recordOutbound() TIDAK PERNAH
     *    mengubah average, cuma qty, lihat kodenya).
     * 3. `recordInbound()` di gudang cabang, HPP dari langkah 1 dioper APA
     *    ADANYA sebagai $unitCost -- TIDAK dihitung ulang dari mana pun.
     *
     * BUKTI aljabar kenapa nilai persediaan total selalu identik
     * sebelum/sesudah (berlaku baik cabang tujuan kosong MAUPUN sudah ada
     * isi, karena weighted average by construction menjaga total nilai):
     *
     *   Nilai_sebelum = qty_pusat*avgCost + qty_cabang*avg_cabang
     *   Nilai_pusat_sesudah = (qty_pusat - qty_transfer) * avgCost   (avg TAK berubah, cuma qty)
     *   Nilai_cabang_sesudah = (qty_cabang + qty_transfer) * new_avg
     *                        = qty_cabang*avg_cabang + qty_transfer*avgCost   (identitas weighted average)
     *   Total_sesudah = Nilai_pusat_sesudah + Nilai_cabang_sesudah
     *                 = qty_pusat*avgCost + qty_cabang*avg_cabang
     *                 = Nilai_sebelum   -- QED, lihat test yang membuktikan ini dengan angka.
     *
     * TIDAK ADA pemanggilan PostingService di mana pun di method ini --
     * nol journal_lines tercipta, dijamin struktural (lihat docblock kelas).
     *
     * @throws InvalidArgumentException kalau status bukan draft.
     */
    public function executeDistribution(StockDistribution $distribution, User $executor): StockDistribution
    {
        if ($distribution->status !== StockDistribution::STATUS_DRAFT) {
            throw new InvalidArgumentException('Distribusi ini sudah bukan draft -- tidak bisa dieksekusi lagi.');
        }

        return DB::transaction(function () use ($distribution, $executor) {
            $distribution->loadMissing('lines.item', 'sourceWarehouse', 'destWarehouse');
            $sourceWarehouse = $distribution->sourceWarehouse;
            $destWarehouse = $distribution->destWarehouse;

            foreach ($distribution->lines->sortBy('item_id') as $line) {
                $item = $line->item;
                $qty = (string) $line->qty;

                $current = $this->inventory->lockAndReadCurrentStock($item, $sourceWarehouse);
                $avgCost = $current['average_cost'];

                $this->inventory->recordOutbound($item, $sourceWarehouse, $qty, $distribution, $distribution->date);
                $this->inventory->recordInbound($item, $destWarehouse, $qty, $avgCost, $distribution, $distribution->date);

                $line->update(['unit_cost' => $avgCost]);
            }

            $distribution->update([
                'status' => StockDistribution::STATUS_COMPLETED,
                'executed_by_user_id' => $executor->id,
                'executed_at' => Carbon::now(),
            ]);

            return $distribution->fresh('lines');
        });
    }

    /**
     * Cuma draft yang boleh dibatalkan -- dokumen `completed` sudah
     * memindahkan stok sungguhan, membatalkannya bukan lagi "batal murni"
     * (butuh distribusi balik eksplisit kalau memang perlu, di luar
     * cakupan Lapisan 2).
     *
     * @throws InvalidArgumentException
     */
    public function cancelDistribution(StockDistribution $distribution): StockDistribution
    {
        if ($distribution->status !== StockDistribution::STATUS_DRAFT) {
            throw new InvalidArgumentException('Cuma distribusi berstatus draft yang bisa dibatalkan.');
        }

        $distribution->update(['status' => StockDistribution::STATUS_CANCELLED]);

        return $distribution->fresh();
    }
}
