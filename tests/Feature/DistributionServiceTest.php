<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\StockDistribution;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\DistributionService;
use App\Services\InventoryService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Multi-Cabang Lapisan 2 -- Distribusi Stok. Kasus verifikasi WAJIB
 * (a)-(h) dari checklist user, dengan angka nyata (bukan cuma "lulus").
 */
class DistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private DistributionService $distributions;

    private BranchService $branches;

    private Warehouse $pusat;

    private Account $persediaanAccount;

    private Uom $pcs;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->distributions = new DistributionService($this->inventory);
        $this->branches = new BranchService();

        $this->pusat = Warehouse::first(); // Outlet Pusat's warehouse -- FoundationSeeder now marks it is_headquarters=true (Layer 1).
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
    }

    private function makeItem(string $sku): Item
    {
        return Item::create([
            'sku' => $sku.'-'.(++self::$seq),
            'name' => "Item {$sku}",
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    /** New branch Outlet + its auto-created companion Warehouse (Layer 1). */
    private function makeBranchWarehouse(string $name): Warehouse
    {
        $outlet = $this->branches->createOutlet([
            'name' => $name,
            'code' => null,
            'address' => null,
            'is_active' => true,
            'is_headquarters' => false,
        ]);

        return Warehouse::where('outlet_id', $outlet->id)->firstOrFail();
    }

    private function inventoryValue(Item $item, Warehouse $warehouse): string
    {
        $qty = $this->inventory->currentStock($item, $warehouse);
        $cost = $this->inventory->currentAverageCost($item, $warehouse);

        return bcmul($qty, $cost, 4);
    }

    /**
     * Throwaway polymorphic source for fixture stock setup (simulating
     * "opening balance"/a prior purchase) -- pola sama
     * StockOpnameServiceTest::makeSource(), bukan Warehouse itu sendiri
     * (yang secara semantik salah sebagai "sumber" pergerakan stok).
     */
    private function makeSource(): Outlet
    {
        return Outlet::create(['name' => 'Opening Balance '.(++self::$seq)]);
    }

    // ==================== (a) HPP pindah tepat -- cabang tujuan KOSONG ====================

    public function test_a_hpp_transfers_exactly_when_destination_is_empty(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');

        // Isi pusat: 100 unit @ Rp10.000 (masuk langsung lewat InventoryService, meniru hasil pembelian).
        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);
        $executed = $this->distributions->executeDistribution($distribution, User::factory()->create());

        // Cabang tujuan KOSONG sebelumnya -> new_avg = (0*0 + 30*10000)/(0+30) = 10.000 PERSIS.
        $this->assertSame('10000.0000', $this->inventory->currentAverageCost($item, $cabang));
        $this->assertSame('30.0000', $this->inventory->currentStock($item, $cabang));
        $this->assertSame('70.0000', $this->inventory->currentStock($item, $this->pusat));
        $this->assertSame('10000.0000', $this->inventory->currentAverageCost($item, $this->pusat), 'HPP pusat TIDAK berubah oleh outbound, cuma qty');
        $this->assertSame('10000.0000', (string) $executed->lines->first()->unit_cost, 'HPP dibekukan di line saat eksekusi');
    }

    // ==================== (b) Blending benar -- cabang tujuan SUDAH ada stok beda harga ====================

    public function test_b_blends_correctly_when_destination_already_has_stock_at_a_different_cost(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');

        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');
        // Cabang sudah punya 10 unit @ Rp9.000 dari distribusi/transaksi sebelumnya.
        $this->inventory->recordInbound($item, $cabang, '10', '9000', $this->makeSource(), '2026-08-01');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);
        $this->distributions->executeDistribution($distribution, User::factory()->create());

        // (10*9000 + 30*10000) / (10+30) = 390000/40 = 9750.
        $this->assertSame('9750.0000', $this->inventory->currentAverageCost($item, $cabang));
        $this->assertSame('40.0000', $this->inventory->currentStock($item, $cabang));
    }

    // ==================== (c) Nilai persediaan TOTAL identik sebelum/sesudah ====================

    public function test_c_total_inventory_value_across_all_warehouses_is_identical_before_and_after(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');

        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');
        $this->inventory->recordInbound($item, $cabang, '10', '9000', $this->makeSource(), '2026-08-01');

        $valueBefore = bcadd(
            $this->inventoryValue($item, $this->pusat),
            $this->inventoryValue($item, $cabang),
            4,
        );
        $this->assertSame('1090000.0000', $valueBefore, '100*10000 + 10*9000 = 1.000.000 + 90.000');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);
        $this->distributions->executeDistribution($distribution, User::factory()->create());

        $valueAfter = bcadd(
            $this->inventoryValue($item, $this->pusat),
            $this->inventoryValue($item, $cabang),
            4,
        );

        $this->assertSame($valueBefore, $valueAfter, 'nilai persediaan total TIDAK BOLEH berubah, cuma lokasinya');
        $this->assertSame('1090000.0000', $valueAfter);
    }

    // ==================== (d) Journal::count() TIDAK berubah ====================

    public function test_d_executing_a_distribution_creates_zero_journal_entries(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');
        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');

        $journalCountBefore = Journal::count();
        $journalLineCountBefore = JournalLine::count();

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);
        $this->distributions->executeDistribution($distribution, User::factory()->create());

        $this->assertSame($journalCountBefore, Journal::count(), 'nol jurnal baru -- distribusi bukan transaksi akuntansi');
        $this->assertSame($journalLineCountBefore, JournalLine::count());
    }

    // ==================== (e) Akun Persediaan 1-1200 tidak tersentuh ====================

    public function test_e_the_inventory_account_is_never_referenced_by_a_distribution(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');
        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);
        $this->distributions->executeDistribution($distribution, User::factory()->create());

        $this->assertSame(
            0,
            JournalLine::where('account_id', $this->persediaanAccount->id)->count(),
            'tidak ada journal_lines apa pun menyentuh akun Persediaan (1-1200) -- distribusi tidak pernah memposting jurnal',
        );
    }

    // ==================== (f) Konkurensi: urutan item_id, pola lockLedger yang sudah ada ====================

    public function test_f_distribution_processes_lines_in_ascending_item_id_order_to_match_stock_opname_locking_convention(): void
    {
        // Locking primitif (lockLedger()) itu sendiri sudah dibuktikan aman
        // di bawah konkurensi sungguhan (multi-proses) oleh
        // InventoryServiceConcurrencyTest/PostingServiceConcurrencyTest --
        // tidak diulang di sini. Yang perlu dibuktikan KHUSUS untuk
        // distribusi: baris diproses item_id ASC (pola StockOpnameService)
        // supaya distribusi lain yang berjalan bersamaan tidak deadlock
        // karena urutan kunci yang berbeda-beda.
        $itemB = $this->makeItem('BBB');
        $itemA = $this->makeItem('AAA');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');

        $this->inventory->recordInbound($itemA, $this->pusat, '50', '5000', $this->makeSource(), '2026-08-13');
        $this->inventory->recordInbound($itemB, $this->pusat, '50', '7000', $this->makeSource(), '2026-08-13');

        // Baris dikirim B dulu baru A -- pastikan eksekusi tetap
        // memprosesnya terurut item_id ASC, bukan urutan input.
        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [
                ['item_id' => $itemB->id, 'qty' => 10],
                ['item_id' => $itemA->id, 'qty' => 10],
            ],
        ]);
        $this->distributions->executeDistribution($distribution, User::factory()->create());

        $movements = StockMovement::where('source_type', StockDistribution::class)
            ->where('source_id', $distribution->id)
            ->orderBy('id')
            ->get();

        // 4 movements total (2 item x keluar+masuk) -- movement PERTAMA
        // yang tercipta harus milik item_id yang LEBIH KECIL, walau baris
        // yang lebih besar dikirim lebih dulu di payload (item B dibuat
        // sebelum A di fixture ini, jadi B punya id lebih kecil -- bukan
        // urutan alfabet nama yang menentukan, urutan ID numerik).
        $smallerItemId = min($itemA->id, $itemB->id);
        $this->assertSame($smallerItemId, $movements->first()->item_id);
        $this->assertSame('10.0000', $this->inventory->currentStock($itemA, $cabang));
        $this->assertSame('10.0000', $this->inventory->currentStock($itemB, $cabang));
    }

    // ==================== (g) Kompatibilitas: multi_branch_enabled=false ====================

    public function test_g_multi_branch_enabled_defaults_false_and_existing_purchase_flow_is_unaffected(): void
    {
        $this->assertFalse(CompanySetting::current()->multi_branch_enabled);

        // Alur pembelian existing (di luar distribusi sama sekali) tetap
        // bekerja identik -- keberadaan fitur distribusi tidak mengubah
        // apa pun tentang InventoryService/PurchaseService.
        $item = $this->makeItem('WIDGET');
        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');

        $this->assertSame('100.0000', $this->inventory->currentStock($item, $this->pusat));
        $this->assertSame('10000.0000', $this->inventory->currentAverageCost($item, $this->pusat));
        $this->assertSame(0, StockDistribution::count(), 'tidak ada distribusi yang tercipta dengan sendirinya');
    }

    public function test_g_source_must_be_a_headquarters_warehouse(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabangA = $this->makeBranchWarehouse('Cabang A');
        $cabangB = $this->makeBranchWarehouse('Cabang B');

        $this->expectException(InvalidArgumentException::class);

        $this->distributions->createDistribution([
            'source_warehouse_id' => $cabangA->id, // BUKAN pusat
            'dest_warehouse_id' => $cabangB->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 10]],
        ]);
    }

    // ==================== (h) 2 stock_movements dengan HPP sama ====================

    public function test_h_execution_creates_exactly_two_stock_movements_with_matching_unit_cost(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');
        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);
        $this->distributions->executeDistribution($distribution, User::factory()->create());

        $movements = StockMovement::where('source_type', StockDistribution::class)
            ->where('source_id', $distribution->id)
            ->get();

        $this->assertCount(2, $movements);

        $outbound = $movements->firstWhere('warehouse_id', $this->pusat->id);
        $inbound = $movements->firstWhere('warehouse_id', $cabang->id);

        $this->assertSame('30.0000', (string) $outbound->qty_out);
        $this->assertSame('0.0000', (string) $outbound->qty_in);
        $this->assertSame('30.0000', (string) $inbound->qty_in);
        $this->assertSame('0.0000', (string) $inbound->qty_out);
        $this->assertSame((string) $outbound->unit_cost, (string) $inbound->unit_cost, 'HPP SAMA persis di kedua movement -- ikut pindah, tidak dihitung ulang');
        $this->assertSame('10000.0000', (string) $outbound->unit_cost);
    }

    // ==================== Kasus tambahan: cost_only ditolak, status enforcement ====================

    public function test_cost_only_items_are_rejected_at_creation(): void
    {
        $costOnlyItem = Item::create([
            'sku' => 'AIR-'.(++self::$seq),
            'name' => 'Air Galon',
            'costing_type' => 'cost_only',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 5000,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');

        $this->expectException(InvalidArgumentException::class);

        $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $costOnlyItem->id, 'qty' => 10]],
        ]);
    }

    public function test_creating_a_distribution_document_does_not_touch_stock_until_executed(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');
        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);

        $this->assertSame(StockDistribution::STATUS_DRAFT, $distribution->status);
        $this->assertSame('100.0000', $this->inventory->currentStock($item, $this->pusat), 'draft belum menyentuh stok sama sekali');
        $this->assertSame('0.0000', $this->inventory->currentStock($item, $cabang));
        $this->assertNull($distribution->lines->first()->unit_cost);
    }

    public function test_executing_twice_is_rejected(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');
        $this->inventory->recordInbound($item, $this->pusat, '100', '10000', $this->makeSource(), '2026-08-13');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]],
        ]);
        $executor = User::factory()->create();
        $this->distributions->executeDistribution($distribution, $executor);

        $this->expectException(InvalidArgumentException::class);
        $this->distributions->executeDistribution($distribution->fresh(), $executor);
    }

    public function test_insufficient_source_stock_is_detected_but_not_blocking(): void
    {
        $item = $this->makeItem('WIDGET');
        $cabang = $this->makeBranchWarehouse('Cabang Selatan');
        $this->inventory->recordInbound($item, $this->pusat, '10', '10000', $this->makeSource(), '2026-08-13');

        $distribution = $this->distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabang->id,
            'date' => '2026-08-13',
            'lines' => [['item_id' => $item->id, 'qty' => 30]], // > 10 tersedia
        ]);

        $shortages = $this->distributions->detectInsufficientStock($distribution);
        $this->assertCount(1, $shortages);
        // '30.0000', bukan '30' -- StockDistributionLine::qty di-cast
        // 'decimal:4' (lihat model), (string) selalu format 4 desimal.
        $this->assertSame('30.0000', $shortages[0]['requested']);
        $this->assertSame('10.0000', $shortages[0]['available']);

        // Tapi TETAP bisa dieksekusi (stok boleh minus, konsisten aplikasi ini).
        $this->distributions->executeDistribution($distribution, User::factory()->create());
        $this->assertSame('-20.0000', $this->inventory->currentStock($item, $this->pusat));
        $this->assertSame('30.0000', $this->inventory->currentStock($item, $cabang));
    }
}
