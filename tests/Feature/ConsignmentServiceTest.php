<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReturn;
use App\Models\Item;
use App\Models\Journal;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\ConsignmentService;
use App\Services\InventoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * "Terima Titipan" & "Retur Titipan" -- lihat rancangan fitur Konsinyasi.
 * Fokus utama di sini: kedua method TIDAK PERNAH memposting jurnal (lihat
 * docblock ConsignmentService) -- Journal::count() harus tetap 0 sepanjang
 * test ini, sekaligus bukti Persediaan di Neraca (yang dihitung murni dari
 * journal_lines) tidak ikut berubah untuk barang yang belum dimiliki toko.
 */
class ConsignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConsignmentService $consignments;

    private InventoryService $inventory;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Uom $pcs;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->consignments = new ConsignmentService($this->inventory);
        $this->warehouse = Warehouse::first();
        $this->supplier = Supplier::create(['name' => 'Pemilik Titipan '.(++self::$seq)]);
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    private function makeConsignmentItem(string $sku): Item
    {
        return Item::create([
            'sku' => $sku.'-'.(++self::$seq),
            'name' => 'Item Titipan '.self::$seq,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
            'is_consignment' => true,
            'consignment_supplier_id' => $this->supplier->id,
        ]);
    }

    public function test_receiving_consignment_stock_sets_qty_and_cost_without_posting_any_journal(): void
    {
        $item = $this->makeConsignmentItem('POCARI');

        $receipt = $this->consignments->receive([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 24, 'unit_cost' => 8000],
            ],
        ]);

        $this->assertInstanceOf(ConsignmentReceipt::class, $receipt);
        $this->assertSame(0, bccomp($this->inventory->currentStock($item, $this->warehouse), '24', 4));
        $this->assertSame(0, bccomp($this->inventory->currentAverageCost($item, $this->warehouse), '8000', 4));

        // Inti fitur: barang belum dimiliki toko, jadi TIDAK PERNAH ada
        // jurnal yang diposting untuk penerimaan ini.
        $this->assertSame(0, Journal::where('source_type', ConsignmentReceipt::class)->count());
        $this->assertSame(0, Journal::count());
    }

    public function test_returning_consignment_stock_reduces_qty_without_posting_any_journal(): void
    {
        $item = $this->makeConsignmentItem('PRIMA');

        $this->consignments->receive([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'unit_cost' => 4000],
            ],
        ]);

        $return = $this->consignments->returnToOwner([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-05',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 3],
            ],
        ]);

        $this->assertInstanceOf(ConsignmentReturn::class, $return);
        $this->assertSame(0, bccomp($this->inventory->currentStock($item, $this->warehouse), '7', 4));

        $line = $return->lines->first();
        $this->assertSame(0, bccomp($line->hpp_value, '12000', 4)); // 3 x 4000

        $this->assertSame(0, Journal::count());
    }

    public function test_receiving_a_non_consignment_item_is_rejected(): void
    {
        $item = Item::create([
            'sku' => 'BIASA-'.(++self::$seq),
            'name' => 'Item Biasa',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->consignments->receive([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 1, 'unit_cost' => 1000],
            ],
        ]);
    }

    public function test_receiving_a_consignment_item_belonging_to_a_different_supplier_is_rejected(): void
    {
        $otherSupplier = Supplier::create(['name' => 'Supplier Lain '.(++self::$seq)]);
        $item = $this->makeConsignmentItem('HYDRO');

        $this->expectException(InvalidArgumentException::class);

        $this->consignments->receive([
            'supplier_id' => $otherSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 1, 'unit_cost' => 1000],
            ],
        ]);
    }
}
