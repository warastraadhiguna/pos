<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockOpnameService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filter "Cabang" (outlet_id) di Riwayat Penjualan, Riwayat per Produk,
 * daftar PO, Riwayat Pembelian per Item, dan daftar Stock Opname.
 */
class BranchFilterTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $pusat;

    private Warehouse $pusatWarehouse;

    private Outlet $cabang;

    private Warehouse $cabangWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        CompanySetting::current()->update(['multi_branch_enabled' => true]);

        $this->pusat = Outlet::first();
        $this->pusatWarehouse = Warehouse::first();
        $this->cabang = app(BranchService::class)->createOutlet([
            'name' => 'Cabang 2', 'code' => null, 'address' => null, 'is_active' => true, 'is_headquarters' => false,
        ]);
        $this->cabangWarehouse = Warehouse::where('outlet_id', $this->cabang->id)->firstOrFail();

        $role = Role::create(['name' => 'Test Role']);
        foreach (['penjualan.view', 'pembelian.manage', 'stock-opname.manage'] as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test'])->id);
        }
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    }

    public function test_sales_history_and_per_product_history_filter_by_branch(): void
    {
        $product = Product::create(['name' => 'Kopi Susu', 'sell_price' => 15000]);
        $pusatSale = $this->sale($this->pusat, $this->pusatWarehouse, $product);
        $cabangSale = $this->sale($this->cabang, $this->cabangWarehouse, $product);

        $range = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'];

        $this->get(route('penjualan.index', [...$range, 'outlet_id' => $this->cabang->id]))
            ->assertInertia(fn ($page) => $page
                ->has('sales', 1)
                ->where('sales.0.id', $cabangSale->id)
                ->where('filters.outlet_id', (string) $this->cabang->id)
                ->has('outlets', 2));

        $this->get(route('penjualan.index', $range))
            ->assertInertia(fn ($page) => $page->has('sales', 2));

        $this->get(route('penjualan-per-produk.index', [...$range, 'outlet_id' => $this->pusat->id]))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.sale_id', $pusatSale->id));
    }

    public function test_purchase_order_list_and_per_item_history_filter_by_branch(): void
    {
        $item = $this->item();
        $pusatPo = $this->receive($this->pusatWarehouse, $item);
        $cabangPo = $this->receive($this->cabangWarehouse, $item);

        $range = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'];

        $this->get(route('pembelian.purchase-orders.index', [...$range, 'outlet_id' => $this->cabang->id]))
            ->assertInertia(fn ($page) => $page
                ->has('purchaseOrders', 1)
                ->where('purchaseOrders.0.id', $cabangPo));

        $this->get(route('pembelian.item-history.index', [...$range, 'outlet_id' => $this->pusat->id]))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.purchase_order_id', $pusatPo));
    }

    public function test_stock_opname_list_filters_by_branch(): void
    {
        $item = $this->item();
        $opnames = app(StockOpnameService::class);
        $opnames->startOpname(['warehouse_id' => $this->pusatWarehouse->id, 'date' => '2026-11-05', 'item_ids' => [$item->id]]);
        $cabangOpname = $opnames->startOpname(['warehouse_id' => $this->cabangWarehouse->id, 'date' => '2026-11-05', 'item_ids' => [$item->id]]);

        $this->get(route('stock-opname.index', ['outlet_id' => $this->cabang->id]))
            ->assertInertia(fn ($page) => $page
                ->has('opnames', 1)
                ->where('opnames.0.id', $cabangOpname->id)
                ->where('outletId', $this->cabang->id));

        $this->get(route('stock-opname.index'))
            ->assertInertia(fn ($page) => $page->has('opnames', 2));
    }

    private function sale(Outlet $outlet, Warehouse $warehouse, Product $product): Sale
    {
        return app(SaleService::class)->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-11-05',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 15000]],
        ]);
    }

    private function item(): Item
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        return Item::create([
            'sku' => 'SUSU-'.uniqid(),
            'name' => 'Susu',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => Account::where('code', '1-1200')->firstOrFail()->id,
        ]);
    }

    private function receive(Warehouse $warehouse, Item $item): int
    {
        $purchases = app(PurchaseService::class);
        $po = $purchases->createPurchaseOrder([
            'supplier_id' => Supplier::firstOrCreate(['name' => 'Supplier'])->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-11-05',
            'lines' => [['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $item->base_uom_id, 'unit_price' => 1000]],
        ]);
        $purchases->receiveGoods($po, [$po->lines->first()->id => 10], '2026-11-05', 'cash');

        return $po->id;
    }
}
