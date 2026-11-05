<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Journal;
use App\Models\Outlet;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Barang masuk saat stok minus (terjual sebelum pembeliannya dicatat) --
 * lihat docblock InventoryService::recordInbound(). Skenario diambil dari
 * insiden produksi Signature Lay.
 */
class InventoryNegativeStockTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private Warehouse $warehouse;

    private Account $persediaan;

    private Account $hpp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->warehouse = Warehouse::first();
        $this->persediaan = Account::where('code', '1-1200')->firstOrFail();
        $this->hpp = Account::where('code', '5-1000')->firstOrFail();
    }

    public function test_inbound_into_negative_stock_resets_average_to_purchase_cost_and_posts_hpp_true_up(): void
    {
        $item = $this->makeItem();
        $source = Outlet::create(['name' => 'Sumber']);

        // 11 terjual sebelum ada pembelian sama sekali -> HPP tercatat 0.
        $this->inventory->recordOutbound($item, $this->warehouse, 11, $source, '2026-10-06');

        $this->inventory->recordInbound($item, $this->warehouse, 5, 5600, $source, '2026-10-23');
        $this->inventory->recordInbound($item, $this->warehouse, 5, 5600, $source, '2026-10-23');
        $this->inventory->recordInbound($item, $this->warehouse, 3, 5600, $source, '2026-10-23');

        $this->assertSame('2.0000', $this->inventory->currentStock($item, $this->warehouse));
        // Dulu: 36399.9998 (seluruh nilai 13 pcs tertumpuk di 2 pcs sisa).
        $this->assertSame('5600.0000', $this->inventory->currentAverageCost($item, $this->warehouse));

        // Hanya penerimaan pertama yang menutup minus dengan rata-rata lama 0:
        // 11 x 5.600 = 61.600 dibebankan ke HPP (penerimaan kedua menutup
        // minus 6 yang rata-ratanya sudah 5.600 -> koreksi 0).
        $journals = Journal::where('memo', 'like', 'Koreksi HPP stok minus%')->with('lines')->get();
        $this->assertCount(1, $journals);
        $lines = $journals->first()->lines;
        $this->assertSame(0, bccomp($lines->firstWhere('account_id', $this->hpp->id)->debit, '61600', 4));
        $this->assertSame(0, bccomp($lines->firstWhere('account_id', $this->persediaan->id)->credit, '61600', 4));
    }

    public function test_inbound_cheaper_than_the_cost_already_charged_reverses_the_excess_hpp(): void
    {
        $item = $this->makeItem();
        $source = Outlet::create(['name' => 'Sumber']);

        $this->inventory->recordInbound($item, $this->warehouse, 10, 7000, $source, '2026-10-01');
        // 12 terjual -> 2 di antaranya keluar dengan rata-rata 7.000.
        $this->inventory->recordOutbound($item, $this->warehouse, 12, $source, '2026-10-02');
        $this->inventory->recordInbound($item, $this->warehouse, 10, 6500, $source, '2026-10-03');

        $this->assertSame('8.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('6500.0000', $this->inventory->currentAverageCost($item, $this->warehouse));

        // -2 x (7.000 - 6.500) = -1.000 -> HPP kelebihan 1.000 dibalik.
        $lines = Journal::where('memo', 'like', 'Koreksi HPP stok minus%')->firstOrFail()->lines;
        $this->assertSame(0, bccomp($lines->firstWhere('account_id', $this->persediaan->id)->debit, '1000', 4));
        $this->assertSame(0, bccomp($lines->firstWhere('account_id', $this->hpp->id)->credit, '1000', 4));
    }

    public function test_normal_inbound_into_positive_or_zero_stock_posts_no_true_up(): void
    {
        $item = $this->makeItem();
        $source = Outlet::create(['name' => 'Sumber']);

        $this->inventory->recordInbound($item, $this->warehouse, 10, 1000, $source, '2026-10-01');
        $this->inventory->recordInbound($item, $this->warehouse, 10, 1200, $source, '2026-10-02');

        $this->assertSame('1100.0000', $this->inventory->currentAverageCost($item, $this->warehouse));
        $this->assertSame(0, Journal::where('memo', 'like', 'Koreksi HPP stok minus%')->count());
    }

    public function test_consignment_item_gets_the_new_average_but_no_journal(): void
    {
        $item = $this->makeItem(['is_consignment' => true]);
        $source = Outlet::create(['name' => 'Sumber']);

        $this->inventory->recordOutbound($item, $this->warehouse, 3, $source, '2026-10-01');
        $this->inventory->recordInbound($item, $this->warehouse, 8, 7000, $source, '2026-10-02');

        $this->assertSame('7000.0000', $this->inventory->currentAverageCost($item, $this->warehouse));
        $this->assertSame(0, Journal::where('memo', 'like', 'Koreksi HPP stok minus%')->count());
    }

    private function makeItem(array $overrides = []): Item
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        return Item::create(array_merge([
            'sku' => 'SIGNT-'.uniqid(),
            'name' => 'Signature Lay',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaan->id,
        ], $overrides));
    }
}
