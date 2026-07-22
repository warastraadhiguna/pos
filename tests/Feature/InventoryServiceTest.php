<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Sale;
use App\Models\Uom;
use App\Models\UomConversion;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private Warehouse $warehouse;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = new InventoryService();
        $this->warehouse = $this->makeWarehouse();
    }

    public function test_two_inbound_movements_produce_the_correct_moving_average(): void
    {
        $item = $this->makeStockedItem();
        $source = $this->makeSource();

        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $source, '2026-07-01');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1200, $source, '2026-07-02');

        $this->assertSame('200.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('1100.0000', $this->inventory->currentAverageCost($item, $this->warehouse));
    }

    public function test_outbound_leaves_average_cost_untouched_and_returns_hpp(): void
    {
        $item = $this->makeStockedItem();
        $source = $this->makeSource();

        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $source, '2026-07-01');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1200, $source, '2026-07-02');

        $hpp = $this->inventory->recordOutbound($item, $this->warehouse, 50, $source, '2026-07-03');

        $this->assertSame('55000.0000', $hpp);
        $this->assertSame('150.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('1100.0000', $this->inventory->currentAverageCost($item, $this->warehouse));
    }

    public function test_cost_only_item_outbound_skips_stock_movement_and_uses_standard_cost(): void
    {
        $item = $this->makeCostOnlyItem(standardCost: 50);
        $source = $this->makeSource();

        $hpp = $this->inventory->recordOutbound($item, $this->warehouse, 10, $source, '2026-07-03');

        $this->assertSame('500.0000', $hpp);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_outbound_beyond_available_stock_is_allowed_and_goes_negative(): void
    {
        $item = $this->makeStockedItem();
        $source = $this->makeSource();

        $this->inventory->recordInbound($item, $this->warehouse, 10, 1000, $source, '2026-07-01');
        $hpp = $this->inventory->recordOutbound($item, $this->warehouse, 15, $source, '2026-07-02');

        $this->assertSame('15000.0000', $hpp);
        $this->assertSame('-5.0000', $this->inventory->currentStock($item, $this->warehouse));
    }

    public function test_convert_to_item_base_uom_returns_qty_unchanged_when_same_uom(): void
    {
        $uom = $this->makeUom();
        $item = $this->makeStockedItemWithUom($uom);

        $this->assertSame(0, bccomp('12.5000', $this->inventory->convertToItemBaseUom($item, $uom, '12.5000'), 4));
    }

    public function test_convert_to_item_base_uom_uses_direct_conversion_factor(): void
    {
        $baseUom = $this->makeUom(); // e.g. GR
        $recipeUom = $this->makeUom(); // e.g. KG
        $item = $this->makeStockedItemWithUom($baseUom);
        UomConversion::create(['from_uom_id' => $recipeUom->id, 'to_uom_id' => $baseUom->id, 'factor' => 1000]);

        // 2 KG recipe -> 2000 GR base.
        $this->assertSame('2000.0000', $this->inventory->convertToItemBaseUom($item, $recipeUom, '2'));
    }

    public function test_convert_to_item_base_uom_uses_inverse_conversion_factor(): void
    {
        $baseUom = $this->makeUom(); // e.g. GR
        $recipeUom = $this->makeUom(); // e.g. KG
        $item = $this->makeStockedItemWithUom($baseUom);
        // Only the KG->GR factor is seeded -- GR->KG must be derived as the inverse.
        UomConversion::create(['from_uom_id' => $baseUom->id, 'to_uom_id' => $recipeUom->id, 'factor' => 0.001]);

        $this->assertSame('2000.0000', $this->inventory->convertToItemBaseUom($item, $recipeUom, '2'));
    }

    public function test_convert_to_item_base_uom_throws_when_no_conversion_path_exists(): void
    {
        $baseUom = $this->makeUom();
        $recipeUom = $this->makeUom();
        $item = $this->makeStockedItemWithUom($baseUom);

        $this->expectException(\RuntimeException::class);
        $this->inventory->convertToItemBaseUom($item, $recipeUom, '1');
    }

    public function test_batch_current_stock_keys_running_qty_by_item_id(): void
    {
        $itemA = $this->makeStockedItem();
        $itemB = $this->makeStockedItem();
        $source = $this->makeSource();

        $this->inventory->recordInbound($itemA, $this->warehouse, 30, 100, $source, '2026-07-01');
        $this->inventory->recordInbound($itemB, $this->warehouse, 5, 100, $source, '2026-07-01');
        $this->inventory->recordOutbound($itemA, $this->warehouse, 10, $source, '2026-07-02');

        $batch = $this->inventory->batchCurrentStock($this->warehouse);

        $this->assertSame(0, bccomp('20.0000', $batch[$itemA->id], 4));
        $this->assertSame(0, bccomp('5.0000', $batch[$itemB->id], 4));
    }

    public function test_producible_qty_is_min_over_bom_components(): void
    {
        $pcs = $this->makeUom();
        $kopi = $this->makeStockedItemWithUom($pcs);
        $gelas = $this->makeStockedItemWithUom($pcs);
        $source = $this->makeSource();

        // 10 kopi tersedia (butuh 1/produk -> cukup untuk 10), tapi hanya
        // 3 gelas (butuh 1/produk -> cukup untuk 3) -- gelas jadi pembatas.
        $this->inventory->recordInbound($kopi, $this->warehouse, 10, 100, $source, '2026-07-01');
        $this->inventory->recordInbound($gelas, $this->warehouse, 3, 100, $source, '2026-07-01');

        $product = Product::create(['name' => 'Kopi Seduh', 'sell_price' => 15000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $kopi->id, 'qty' => 1, 'uom_id' => $pcs->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $pcs->id]);

        $result = $this->inventory->producibleQtyForProducts($this->loadForProducibleQty($product->id), $this->warehouse);

        $this->assertSame(3, $result[$product->id]);
    }

    public function test_producible_qty_skips_cost_only_components(): void
    {
        $pcs = $this->makeUom();
        $kopi = $this->makeStockedItemWithUom($pcs);
        $air = $this->makeCostOnlyItem(standardCost: 10);
        $source = $this->makeSource();

        $this->inventory->recordInbound($kopi, $this->warehouse, 5, 100, $source, '2026-07-01');

        $product = Product::create(['name' => 'Kopi Seduh', 'sell_price' => 15000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $kopi->id, 'qty' => 1, 'uom_id' => $pcs->id]);
        // Air (cost_only) tidak pernah punya stock_movements -- kalau tidak
        // dilewati, currentStock()-nya yang '0.0000' akan mengenolkan
        // seluruh producible qty walau kopi masih banyak.
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $air->id, 'qty' => 1, 'uom_id' => $pcs->id]);

        $result = $this->inventory->producibleQtyForProducts($this->loadForProducibleQty($product->id), $this->warehouse);

        $this->assertSame(5, $result[$product->id]);
    }

    public function test_producible_qty_is_null_when_product_has_no_components(): void
    {
        $product = Product::create(['name' => 'Jasa Cuci Gelas', 'sell_price' => 2000]);

        $result = $this->inventory->producibleQtyForProducts($this->loadForProducibleQty($product->id), $this->warehouse);

        $this->assertNull($result[$product->id]);
    }

    public function test_producible_qty_is_null_when_every_component_is_cost_only(): void
    {
        $pcs = $this->makeUom();
        $air = $this->makeCostOnlyItem(standardCost: 10);

        $product = Product::create(['name' => 'Air Putih', 'sell_price' => 1000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $air->id, 'qty' => 1, 'uom_id' => $pcs->id]);

        $result = $this->inventory->producibleQtyForProducts($this->loadForProducibleQty($product->id), $this->warehouse);

        $this->assertNull($result[$product->id]);
    }

    public function test_producible_qty_is_clamped_to_zero_for_negative_stock(): void
    {
        $pcs = $this->makeUom();
        $kopi = $this->makeStockedItemWithUom($pcs);
        $source = $this->makeSource();

        $this->inventory->recordInbound($kopi, $this->warehouse, 2, 100, $source, '2026-07-01');
        $this->inventory->recordOutbound($kopi, $this->warehouse, 5, $source, '2026-07-02');
        $this->assertSame('-3.0000', $this->inventory->currentStock($kopi, $this->warehouse));

        $product = Product::create(['name' => 'Kopi Seduh', 'sell_price' => 15000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $kopi->id, 'qty' => 1, 'uom_id' => $pcs->id]);

        $result = $this->inventory->producibleQtyForProducts($this->loadForProducibleQty($product->id), $this->warehouse);

        $this->assertSame(0, $result[$product->id]);
    }

    public function test_producible_qty_converts_recipe_uom_to_item_base_uom(): void
    {
        $baseUom = $this->makeUom(); // GR
        $recipeUom = $this->makeUom(); // KG
        $gula = $this->makeStockedItemWithUom($baseUom);
        $source = $this->makeSource();

        UomConversion::create(['from_uom_id' => $recipeUom->id, 'to_uom_id' => $baseUom->id, 'factor' => 1000]);
        // 5000 GR stok tersedia.
        $this->inventory->recordInbound($gula, $this->warehouse, 5000, 10, $source, '2026-07-01');

        $product = Product::create(['name' => 'Kopi Manis', 'sell_price' => 15000]);
        // Resep butuh 0.5 KG (=500 GR) per produk -> 5000/500 = 10 producible.
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $gula->id, 'qty' => '0.5', 'uom_id' => $recipeUom->id]);

        $result = $this->inventory->producibleQtyForProducts($this->loadForProducibleQty($product->id), $this->warehouse);

        $this->assertSame(10, $result[$product->id]);
    }

    /**
     * @return Collection<int, Product>
     */
    private function loadForProducibleQty(int $productId): Collection
    {
        return Product::with(['components.item', 'components.uom'])->whereKey($productId)->get();
    }

    private function makeStockedItemWithUom(Uom $uom): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode('SKU'),
            'name' => 'Test Stocked Item',
            'costing_type' => 'stocked',
            'base_uom_id' => $uom->id,
            'purchase_uom_id' => $uom->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->makeAccount()->id,
        ]);
    }

    private function makeWarehouse(): Warehouse
    {
        $outlet = Outlet::create(['name' => 'Outlet Pusat']);

        return Warehouse::create(['outlet_id' => $outlet->id, 'name' => 'Gudang Utama']);
    }

    private function makeSource(): Sale
    {
        return Sale::create([
            'outlet_id' => $this->warehouse->outlet_id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'local_uuid' => (string) Str::uuid(),
            'subtotal' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'status' => 'completed',
        ]);
    }

    private function makeStockedItem(): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode('SKU'),
            'name' => 'Test Stocked Item',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->makeUom()->id,
            'purchase_uom_id' => $this->makeUom()->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->makeAccount()->id,
        ]);
    }

    private function makeCostOnlyItem(float $standardCost): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode('SKU'),
            'name' => 'Test Cost Only Item',
            'costing_type' => 'cost_only',
            'base_uom_id' => $this->makeUom()->id,
            'purchase_uom_id' => $this->makeUom()->id,
            'standard_cost' => $standardCost,
            'inventory_account_id' => $this->makeAccount()->id,
        ]);
    }

    private function makeUom(): Uom
    {
        return Uom::create(['code' => $this->uniqueCode('UOM'), 'name' => 'Unit']);
    }

    private function makeAccount(): Account
    {
        return Account::create([
            'code' => $this->uniqueCode('ACC'),
            'name' => 'Persediaan Test',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix.'-'.(++self::$seq);
    }
}
