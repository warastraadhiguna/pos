<?php

namespace App\Services;

use App\Exceptions\StockChangedSinceOpnameStartedException;
use App\Exceptions\VoidConfirmationRequiredException;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\StockMovement;
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
     * is guaranteed to equal counted_qty exactly. system_qty is overwritten
     * with that live stock, so the stored line always reads
     * counted - system = diff.
     *
     * Itu hanya benar kalau barang dihitung SETELAH semua mutasi yang sudah
     * tercatat. Kalau stok item bergerak sejak draft dibuat (lihat
     * stockMovementsSinceStart()), posting ditolak dengan
     * StockChangedSinceOpnameStartedException kecuali $confirmStockChanged
     * true -- kasir menyatakan sudah menghitung ulang setelah mutasi itu.
     *
     * Surplus is recorded via InventoryService::recordInbound() valued at
     * the item's current running_average_cost, so it never shifts the
     * moving average — it's simply "found" stock at the price already on
     * the books. Shrinkage goes through recordOutbound(), which values it
     * at the current average automatically.
     *
     * @param  array<int, int|float|string>  $countedQuantities  [stock_opname_line_id => counted_qty]
     */
    public function postOpname(StockOpname $opname, array $countedQuantities, DateTimeInterface|string $date, bool $confirmStockChanged = false): StockOpname
    {
        return DB::transaction(function () use ($opname, $countedQuantities, $date, $confirmStockChanged) {
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

            if (! $confirmStockChanged) {
                $moved = collect($this->stockMovementsSinceStart($opname))
                    ->filter(fn (array $m) => bccomp($m['qty_in'], '0', self::SCALE) !== 0 || bccomp($m['qty_out'], '0', self::SCALE) !== 0);

                if ($moved->isNotEmpty()) {
                    $names = $opname->lines->whereIn('item_id', $moved->keys())->map(fn ($line) => $line->item->name)->implode(', ');

                    throw new StockChangedSinceOpnameStartedException(
                        "Stok berubah sejak opname dibuat ({$names}). Hitung ulang barang tersebut, lalu centang konfirmasi sebelum posting."
                    );
                }
            }

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
                    'system_qty' => $liveStock,
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

    /**
     * Item yang stoknya akan jadi minus kalau opname yang SUDAH diposting
     * ini dibatalkan -- surplus yang dulu ditambahkan ternyata sudah
     * terjual. Bukan larangan, wajib konfirmasi (lihat cancelOpname()).
     *
     * @return array<int, array{name: string, qty: string, stock: string}>
     */
    public function cancelWarnings(StockOpname $opname): array
    {
        if ($opname->status !== 'completed') {
            return [];
        }

        $warnings = [];
        $surplus = $opname->stockMovements()->with('item')->where('qty_in', '>', 0)->get()->groupBy('item_id');

        foreach ($surplus as $movements) {
            $item = $movements->first()->item;
            $qty = $movements->reduce(fn (string $carry, StockMovement $m) => bcadd($carry, (string) $m->qty_in, self::SCALE), '0');
            $stock = $this->inventory->currentStock($item, $opname->warehouse);

            if (bccomp($stock, $qty, self::SCALE) < 0) {
                $warnings[] = ['name' => $item->name, 'qty' => $qty, 'stock' => $stock];
            }
        }

        return $warnings;
    }

    /**
     * Batalkan stock opname.
     * - Draft: cukup ganti status, belum ada stok/jurnal yang tersentuh.
     * - Sudah diposting: setiap mutasinya dibalik dengan harga yang sama
     *   (surplus ditarik lewat recordOutboundAtCost(), susut dikembalikan
     *   lewat recordInbound()) dan jurnal penyesuaiannya dibalik, bertanggal
     *   sama dengan opname -- pola sama PurchaseService::voidGoodsReceipt().
     *
     * Kalau pembatalan membuat stok minus (cancelWarnings()), ditolak
     * dengan VoidConfirmationRequiredException kecuali $confirmed.
     */
    public function cancelOpname(StockOpname $opname, ?string $reason, ?int $userId, bool $confirmed = false): StockOpname
    {
        return DB::transaction(function () use ($opname, $reason, $userId, $confirmed) {
            $locked = StockOpname::query()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new RuntimeException("Stock opname #{$locked->id} sudah dibatalkan.");
            }

            if ($locked->status === 'completed') {
                if (! $confirmed && $this->cancelWarnings($locked) !== []) {
                    throw new VoidConfirmationRequiredException(
                        "Sebagian stok tambahan dari opname #{$locked->id} sudah terpakai. Konfirmasi pembatalan diperlukan."
                    );
                }

                $date = $locked->date;
                $movements = $locked->stockMovements()->with('item')->orderBy('item_id')->orderBy('id')->get();

                foreach ($movements as $movement) {
                    if (bccomp((string) $movement->qty_in, '0', self::SCALE) > 0) {
                        $this->inventory->recordOutboundAtCost($movement->item, $locked->warehouse, $movement->qty_in, $movement->unit_cost, $locked, $date);
                    }

                    if (bccomp((string) $movement->qty_out, '0', self::SCALE) > 0) {
                        $this->inventory->recordInbound($movement->item, $locked->warehouse, $movement->qty_out, $movement->unit_cost, $locked, $date);
                    }
                }

                $journals = Journal::where('source_type', $locked->getMorphClass())
                    ->where('source_id', $locked->id)
                    ->where('memo', 'like', 'Penyesuaian stock opname%')
                    ->with('lines.account')
                    ->get();

                foreach ($journals as $journal) {
                    $this->posting->post(
                        lines: $journal->lines->map(fn (JournalLine $line) => [
                            'account' => $line->account,
                            'debit' => $line->credit,
                            'credit' => $line->debit,
                        ])->all(),
                        date: $date,
                        source: $locked,
                        memo: "Pembatalan stock opname #{$locked->id}",
                    );
                }
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $userId,
                'cancel_reason' => $reason,
            ]);

            return $locked->fresh('lines');
        });
    }

    /**
     * Mutasi stok tiap item opname yang tercatat SETELAH draft dibuat (di
     * luar mutasi opname ini sendiri) -- dipakai untuk menolak posting
     * (postOpname) dan untuk peringatan di halaman draft.
     *
     * @return array<int, array{qty_in: string, qty_out: string}> [item_id => total]
     */
    public function stockMovementsSinceStart(StockOpname $opname): array
    {
        $itemIds = $opname->lines()->pluck('item_id');

        return StockMovement::query()
            ->where('warehouse_id', $opname->warehouse_id)
            ->whereIn('item_id', $itemIds)
            ->where('created_at', '>', $opname->created_at)
            ->whereNot(fn ($q) => $q->where('source_type', $opname->getMorphClass())->where('source_id', $opname->id))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty_in) as qty_in, SUM(qty_out) as qty_out')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->item_id => ['qty_in' => (string) $row->qty_in, 'qty_out' => (string) $row->qty_out]])
            ->all();
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
