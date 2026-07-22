<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\StockOpnameService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class StockOpnameServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private StockOpnameService $opnames;

    private Warehouse $warehouse;

    private Uom $pcs;

    private Uom $ml;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->opnames = new StockOpnameService($this->inventory, new PostingService());

        $this->warehouse = Warehouse::first();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->ml = Uom::where('code', 'ML')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    public function test_start_opname_snapshots_system_qty_and_does_not_touch_stock_or_ledger(): void
    {
        $item = $this->makeStockedItem('WIDGET');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$item->id],
        ]);

        $this->assertSame('draft', $opname->status);
        $this->assertSame(1, $opname->lines->count());

        $line = $opname->lines->first();
        $this->assertSame(0, bccomp($line->system_qty, '100', 4));
        $this->assertSame(0, bccomp($line->counted_qty, '0', 4));

        // Belum menyentuh stok atau jurnal sama sekali.
        $this->assertSame('100.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame(0, Journal::count());
    }

    public function test_post_opname_with_surplus_increases_stock_without_shifting_average_cost(): void
    {
        $item = $this->makeStockedItem('WIDGET');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$item->id],
        ]);
        $line = $opname->lines->first();

        $posted = $this->opnames->postOpname($opname, [$line->id => 110], '2026-07-10');

        $this->assertSame('completed', $posted->status);
        $this->assertSame('110.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('1000.0000', $this->inventory->currentAverageCost($item, $this->warehouse));

        $postedLine = $posted->lines->first();
        $this->assertSame(0, bccomp($postedLine->counted_qty, '110', 4));
        $this->assertSame(0, bccomp($postedLine->diff_qty, '10', 4));

        $journal = Journal::where('source_type', StockOpname::class)->where('source_id', $opname->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get();

        $this->assertSame(2, $lines->count());
        $this->assertSame(0, bccomp($lines->firstWhere('account.code', '1-1200')->debit, '10000', 4));
        $this->assertSame(0, bccomp($lines->firstWhere('account.code', '5-2000')->credit, '10000', 4));
    }

    public function test_post_opname_with_shrinkage_decreases_stock_and_posts_journal(): void
    {
        $item = $this->makeStockedItem('WIDGET');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$item->id],
        ]);
        $line = $opname->lines->first();

        $posted = $this->opnames->postOpname($opname, [$line->id => 90], '2026-07-10');

        $this->assertSame('90.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('1000.0000', $this->inventory->currentAverageCost($item, $this->warehouse));

        $postedLine = $posted->lines->first();
        $this->assertSame(0, bccomp($postedLine->diff_qty, '-10', 4));

        $journal = Journal::where('source_type', StockOpname::class)->where('source_id', $opname->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get();

        $this->assertSame(2, $lines->count());
        $this->assertSame(0, bccomp($lines->firstWhere('account.code', '5-2000')->debit, '10000', 4));
        $this->assertSame(0, bccomp($lines->firstWhere('account.code', '1-1200')->credit, '10000', 4));
    }

    public function test_post_opname_with_mixed_surplus_and_shrinkage_posts_one_balanced_journal(): void
    {
        $itemA = $this->makeStockedItem('WIDGET-A');
        $itemB = $this->makeStockedItem('WIDGET-B');
        $this->inventory->recordInbound($itemA, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');
        $this->inventory->recordInbound($itemB, $this->warehouse, 50, 2000, $this->makeSource(), '2026-07-01');

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$itemA->id, $itemB->id],
        ]);
        $lineA = $opname->lines->firstWhere('item_id', $itemA->id);
        $lineB = $opname->lines->firstWhere('item_id', $itemB->id);

        // A surplus 10 @1000 = 10000 | B shrinkage 10 @2000 = 20000
        $this->opnames->postOpname($opname, [
            $lineA->id => 110,
            $lineB->id => 40,
        ], '2026-07-10');

        $this->assertSame('110.0000', $this->inventory->currentStock($itemA, $this->warehouse));
        $this->assertSame('40.0000', $this->inventory->currentStock($itemB, $this->warehouse));

        $journal = Journal::where('source_type', StockOpname::class)->where('source_id', $opname->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get();

        $this->assertSame(4, $lines->count());

        $totalDebit = $lines->reduce(fn ($carry, JournalLine $l) => bcadd($carry, $l->debit, 4), '0');
        $totalCredit = $lines->reduce(fn ($carry, JournalLine $l) => bcadd($carry, $l->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
        $this->assertSame(0, bccomp($totalDebit, '30000', 4));

        $persediaanLines = $lines->where('account.code', '1-1200');
        $this->assertSame(0, bccomp($persediaanLines->sum('debit'), '10000', 4));
        $this->assertSame(0, bccomp($persediaanLines->sum('credit'), '20000', 4));

        $selisihLines = $lines->where('account.code', '5-2000');
        $this->assertSame(0, bccomp($selisihLines->sum('credit'), '10000', 4));
        $this->assertSame(0, bccomp($selisihLines->sum('debit'), '20000', 4));
    }

    /**
     * Bukti langsung (non-concurrency) bahwa postOpname() benar-benar
     * mengurutkan baris berdasarkan item_id ASC sebelum diproses — bukan
     * cuma mengikuti urutan array `item_ids`/urutan insersi baris opname.
     * Ini penting untuk mencegah deadlock lock-order kalau dua opname (atau
     * opname + transaksi lain) mengunci beberapa item yang sama dengan
     * urutan berbeda (lihat StockOpnameServiceConcurrencyTest untuk race
     * konkurensi sungguhan; ini cuma memverifikasi urutan pemrosesannya).
     */
    public function test_post_opname_processes_lines_in_ascending_item_id_order(): void
    {
        $itemLowId = $this->makeStockedItem('WIDGET-LOW'); // dibuat duluan -> id lebih kecil
        $itemHighId = $this->makeStockedItem('WIDGET-HIGH'); // dibuat belakangan -> id lebih besar
        $this->assertLessThan($itemHighId->id, $itemLowId->id);

        $this->inventory->recordInbound($itemLowId, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');
        $this->inventory->recordInbound($itemHighId, $this->warehouse, 50, 2000, $this->makeSource(), '2026-07-01');

        // Sengaja daftarkan item ber-ID BESAR duluan, ID KECIL belakangan —
        // kalau postOpname() cuma mengikuti urutan array/insersi (bukan
        // benar-benar mengurutkan ulang item_id ASC), movement untuk item
        // ber-ID besar akan tertulis LEBIH DULU, membalik urutan yang
        // diharapkan di bawah.
        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$itemHighId->id, $itemLowId->id],
        ]);
        $lineLow = $opname->lines->firstWhere('item_id', $itemLowId->id);
        $lineHigh = $opname->lines->firstWhere('item_id', $itemHighId->id);

        $maxMovementIdBefore = StockMovement::max('id') ?? 0;

        // Keduanya diberi diff != 0 (surplus & shrinkage) supaya KEDUANYA
        // memicu recordInbound()/recordOutbound() — dua movement baru yang
        // urutan penciptaannya (id auto-increment) mencerminkan urutan
        // pemrosesan baris di dalam loop.
        $this->opnames->postOpname($opname, [
            $lineLow->id => 110,
            $lineHigh->id => 40,
        ], '2026-07-10');

        $newMovements = StockMovement::where('id', '>', $maxMovementIdBefore)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $newMovements);
        $this->assertSame(
            $itemLowId->id,
            $newMovements[0]->item_id,
            'Item ber-ID lebih kecil harus diproses (movement-nya tertulis) lebih dulu, meski didaftarkan belakangan di item_ids.',
        );
        $this->assertSame($itemHighId->id, $newMovements[1]->item_id);
    }

    public function test_cost_only_item_cannot_be_included_in_opname(): void
    {
        $air = $this->makeCostOnlyItem('AIR', '200');

        $this->expectException(InvalidArgumentException::class);

        $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$air->id],
        ]);
    }

    public function test_posting_an_already_completed_opname_throws(): void
    {
        $item = $this->makeStockedItem('WIDGET');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$item->id],
        ]);
        $line = $opname->lines->first();

        $this->opnames->postOpname($opname, [$line->id => 100], '2026-07-10');

        $this->expectException(RuntimeException::class);
        $this->opnames->postOpname($opname->fresh(), [$line->id => 100], '2026-07-11');
    }

    public function test_missing_counted_qty_rolls_back_the_entire_post(): void
    {
        $itemA = $this->makeStockedItem('WIDGET-A');
        $itemB = $this->makeStockedItem('WIDGET-B');
        $this->inventory->recordInbound($itemA, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');
        $this->inventory->recordInbound($itemB, $this->warehouse, 50, 2000, $this->makeSource(), '2026-07-01');

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'item_ids' => [$itemA->id, $itemB->id],
        ]);
        $lineA = $opname->lines->firstWhere('item_id', $itemA->id);

        $stockMovementCountBefore = StockMovement::count();

        try {
            // Hanya lineA yang diberi counted_qty — lineB akan memicu
            // InvalidArgumentException di tengah loop, setelah lineA
            // (yang sempat "berhasil" diproses) — membuktikan itu ikut rollback.
            $this->opnames->postOpname($opname, [$lineA->id => 110], '2026-07-10');

            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame('draft', $opname->fresh()->status);
        $this->assertSame('100.0000', $this->inventory->currentStock($itemA, $this->warehouse));
        $this->assertSame($stockMovementCountBefore, StockMovement::count());
        $this->assertSame(0, Journal::count());
    }

    private function makeStockedItem(string $sku): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode($sku),
            'name' => $sku,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function makeCostOnlyItem(string $sku, string $standardCost): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode($sku),
            'name' => $sku,
            'costing_type' => 'cost_only',
            'base_uom_id' => $this->ml->id,
            'purchase_uom_id' => $this->ml->id,
            'standard_cost' => $standardCost,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function makeSource(): Outlet
    {
        return Outlet::create(['name' => 'Opening Balance '.(++self::$seq)]);
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix.'-'.(++self::$seq);
    }
}
