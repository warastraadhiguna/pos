<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
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
}
