<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    /**
     * Dashboard "Transaksi Terbaru" (Dashboard.jsx) sebelumnya cuma
     * mengirim `date` (hari kalender, tanpa jam) -- beda dari
     * Penjualan/Index.jsx & Show.jsx yang SUDAH mengirim `occurred_at`
     * (momen transaksi sebenarnya) supaya bisa ditampilkan sebagai
     * tanggal+jam WIB. Test ini mengunci perbaikannya: `occurred_at` HARUS
     * ikut terkirim, bukan cuma `date`.
     */
    public function test_recent_sales_include_occurred_at_for_date_and_time_display(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $outlet = Outlet::first();
        $warehouse = Warehouse::first();
        $product = Product::create(['name' => 'Kopi Sachet', 'sell_price' => 5000]);

        $sales = app(SaleService::class);
        $sale = $sales->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000],
            ],
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('recentSales.0.id', $sale->id)
            ->where('recentSales.0.occurred_at', fn ($value) => $value !== null)
        );
    }
    /**
     * Transaksi yang dibatalkan (void) tidak boleh ikut dihitung di kartu
     * penjualan dashboard maupun ringkasan Riwayat Penjualan -- tetap
     * tampil di daftar (jejak audit), tapi totalnya hanya yang 'completed'.
     */
    public function test_voided_sales_are_excluded_from_dashboard_and_history_totals(): void
    {
        $role = \App\Models\Role::create(['name' => 'Test Role']);
        $role->permissions()->attach(\App\Models\Permission::firstOrCreate(['key' => 'penjualan.view'], ['label' => 'x', 'group' => 'x'])->id);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        $product = Product::create(['name' => 'Kopi', 'sell_price' => 5000]);
        $sales = app(SaleService::class);
        $make = fn (int $price, string $method) => $sales->createSale([
            'outlet_id' => Outlet::first()->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => now()->toDateString(),
            'payment_method' => $method,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $price]],
        ]);
        $make(5000, 'cash');
        $demo = $make(7000, 'cash');
        $sales->voidSale($demo, 'Data demo', $user->id);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('stats.sales_today_count', 1)
            ->where('stats.sales_today_total', fn ($v) => (float) $v === 5000.0)
            ->where('stats.sales_month_total', fn ($v) => (float) $v === 5000.0));

        $today = now()->toDateString();
        $this->get(route('penjualan.index', ['date_from' => $today, 'date_to' => $today]))
            ->assertInertia(fn ($page) => $page
                ->has('sales', 2)
                ->where('summary.count', 1)
                ->where('summary.total', fn ($v) => (float) $v === 5000.0)
                ->has('summary.by_method', 1)
                ->where('summary.by_method.0.total', fn ($v) => (float) $v === 5000.0));
    }

    public function test_dashboard_lists_active_items_with_negative_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $warehouse = Warehouse::first();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $make = fn (string $sku, bool $active) => Item::create([
            'sku' => $sku, 'name' => $sku, 'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id, 'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0, 'inventory_account_id' => $account->id, 'is_active' => $active,
        ]);
        $minus = $make('MINUS', true);
        $positive = $make('PLUS', true);
        $inactive = $make('NONAKTIF', false);

        $inventory = app(InventoryService::class);
        $inventory->recordOutbound($minus, $warehouse, 3, $warehouse, now()->toDateString());
        $inventory->recordInbound($positive, $warehouse, 5, 1000, $warehouse, now()->toDateString());
        $inventory->recordOutbound($inactive, $warehouse, 1, $warehouse, now()->toDateString());

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->has('negativeStockItems', 1)
            ->where('negativeStockItems.0.sku', 'MINUS')
            ->where('negativeStockItems.0.qty', '-3.0000')
        );
    }
}
