<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockOpname;
use App\Models\Warehouse;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StockOpnameService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by Database\Seeders\FoundationSeeder.
    // There is only one variance account for both directions (see
    // docs/ROADMAP.md "utang teknis" discussion) — shrinkage debits it,
    // surplus credits it, netting to a single "selisih persediaan" figure.
    private const ACCOUNT_PERSEDIAAN = '1-1200';

    private const ACCOUNT_SELISIH_PERSEDIAAN = '5-2000';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
    ) {}

    /**
     * Start a stock opname session: snapshot the currently tracked stock
     * (system_qty) for each item to be physically counted. Never touches
     * stock or the ledger.
     *
     * cost_only items have no stock_movements ledger at all, so "counting"
     * them is meaningless — including one is rejected outright.
     *
     * @param  array{warehouse_id: int, date: DateTimeInterface|string, item_ids: array<int, int>}  $data
     */
    public function startOpname(array $data): StockOpname
    {
        return DB::transaction(function () use ($data) {
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);

            $opname = new StockOpname([
                'warehouse_id' => $warehouse->id,
                'date' => $data['date'],
                'status' => 'draft',
            ]);
            $opname->save();

            foreach ($data['item_ids'] as $itemId) {
                $item = Item::findOrFail($itemId);

                if ($item->costing_type !== 'stocked') {
                    throw new InvalidArgumentException(
                        "Item [{$item->sku}] is cost_only and has no tracked stock — it cannot be included in a stock opname."
                    );
                }

                $opname->lines()->create([
                    'item_id' => $item->id,
                    'system_qty' => $this->inventory->currentStock($item, $warehouse),
                    'counted_qty' => 0,
                    'diff_qty' => 0,
                ]);
            }

            return $opname->fresh('lines');
        });
    }

    /**
     * Finalize an opname with the physically counted quantities.
     *
     * The diff is computed against LIVE stock at posting time — not the
     * system_qty snapshotted in startOpname() — so the resulting running_qty
     * is guaranteed to equal counted_qty exactly even if other transactions
     * moved stock while the physical count was in progress. system_qty
     * stays untouched as an audit record of what was expected when counting
     * began; it is not the basis for diff_qty.
     *
     * Surplus is recorded via InventoryService::recordInbound() valued at
     * the item's current running_average_cost, so it never shifts the
     * moving average — it's simply "found" stock at the price already on
     * the books. Shrinkage goes through recordOutbound(), which values it
     * at the current average automatically.
     *
     * @param  array<int, int|float|string>  $countedQuantities  [stock_opname_line_id => counted_qty]
     */
    public function postOpname(StockOpname $opname, array $countedQuantities, DateTimeInterface|string $date): StockOpname
    {
        return DB::transaction(function () use ($opname, $countedQuantities, $date) {
            // Kunci baris opname ITU SENDIRI dulu, lalu baca ulang statusnya
            // di dalam lock — menutup race terpisah di mana dua panggilan
            // postOpname() yang genuinely konkuren untuk OPNAME YANG SAMA
            // (double-click tombol posting, atau retry jaringan) sama-sama
            // lolos cek status 'draft' sebelum salah satu commit, yang akan
            // menghasilkan penyesuaian ganda. Re-fetch (bukan pakai variabel
            // $opname yang dioper caller) supaya status yang dicek adalah
            // status TERKINI, bukan snapshot dari sebelum lock ada.
            $opname = StockOpname::query()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($opname->status !== 'draft') {
                throw new RuntimeException("Stock opname #{$opname->id} has already been posted or cancelled.");
            }

            $opname->loadMissing('lines.item');
            $warehouse = $opname->warehouse;

            $totalSurplusValue = '0';
            $totalShrinkageValue = '0';

            // Urutkan berdasarkan item_id ASC — kalau ada transaksi konkuren
            // lain yang juga mengunci beberapa item yang sama (opname lain,
            // atau apa pun), urutan lock yang konsisten ini mencegah
            // deadlock lock-order (A mengunci 1 lalu menunggu 2, B mengunci
            // 2 lalu menunggu 1).
            $lines = $opname->lines->sortBy(fn ($line) => $line->item_id)->values();

            foreach ($lines as $line) {
                if (! array_key_exists($line->id, $countedQuantities)) {
                    throw new InvalidArgumentException(
                        "Missing counted qty for stock_opname_line #{$line->id} (item [{$line->item->sku}])."
                    );
                }

                $item = $line->item;
                $countedQty = (string) $countedQuantities[$line->id];

                // Kunci DULU, baru baca stok & avg cost dari DALAM lock itu
                // — bukan currentStock()/currentAverageCost() biasa (tanpa
                // lock) seperti sebelumnya. Itu meninggalkan celah TOCTOU:
                // diff yang dihitung dari bacaan tanpa lock bisa jadi basi
                // begitu recordInbound()/recordOutbound() akhirnya menulis,
                // kalau ada transaksi lain (sale, goods-receipt, opname
                // lain) menyelip mengubah stok item ini di jendela antara
                // baca dan tulis. Mengunci di sini membuat baca-lalu-tulis
                // jadi satu rentang lock yang tidak terputus.
                $locked = $this->inventory->lockAndReadCurrentStock($item, $warehouse);
                $liveStock = $locked['qty'];
                $diff = bcsub($countedQty, $liveStock, self::SCALE);

                $line->update([
                    'counted_qty' => $countedQty,
                    'diff_qty' => $diff,
                ]);

                if (bccomp($diff, '0', self::SCALE) === 0) {
                    continue;
                }

                if (bccomp($diff, '0', self::SCALE) > 0) {
                    $avgCost = $locked['average_cost'];
                    $this->inventory->recordInbound($item, $warehouse, $diff, $avgCost, $opname, $date);
                    $totalSurplusValue = bcadd($totalSurplusValue, bcmul($diff, $avgCost, self::SCALE), self::SCALE);
                } else {
                    $shrinkQty = bcmul($diff, '-1', self::SCALE);
                    $hpp = $this->inventory->recordOutbound($item, $warehouse, $shrinkQty, $opname, $date);
                    $totalShrinkageValue = bcadd($totalShrinkageValue, $hpp, self::SCALE);
                }
            }

            $this->postAdjustmentJournal($opname, $totalSurplusValue, $totalShrinkageValue, $date);

            $opname->update(['status' => 'completed']);

            return $opname->fresh('lines');
        });
    }

    private function postAdjustmentJournal(StockOpname $opname, string $surplusValue, string $shrinkageValue, DateTimeInterface|string $date): void
    {
        $lines = [];

        if (bccomp($surplusValue, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => self::ACCOUNT_PERSEDIAAN, 'debit' => $surplusValue, 'credit' => 0];
            $lines[] = ['account' => self::ACCOUNT_SELISIH_PERSEDIAAN, 'debit' => 0, 'credit' => $surplusValue];
        }

        if (bccomp($shrinkageValue, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => self::ACCOUNT_SELISIH_PERSEDIAAN, 'debit' => $shrinkageValue, 'credit' => 0];
            $lines[] = ['account' => self::ACCOUNT_PERSEDIAAN, 'debit' => 0, 'credit' => $shrinkageValue];
        }

        if ($lines === []) {
            return;
        }

        $this->posting->post(
            lines: $lines,
            date: $date,
            source: $opname,
            memo: "Penyesuaian stock opname #{$opname->id}",
        );
    }
}
