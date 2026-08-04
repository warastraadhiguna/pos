<?php

namespace Tests\Feature\Api;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\DraftSyncService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use App\Services\SalesReportService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $sales;

    private SalesReportService $reports;

    private Outlet $outlet;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->sales = new SaleService(new InventoryService(), new PostingService(), new CashAccountService(), new DraftSyncService());
        $this->reports = new SalesReportService();

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeSale(string $date, int $productId, float $price): void
    {
        $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => [['product_id' => $productId, 'qty' => 1, 'unit_price' => $price]],
        ]);
    }

    private function bearerToken(): string
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        $token = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_name' => 'Test Device',
        ])->json('token');

        return $token;
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/today')->assertUnauthorized();
    }

    public function test_summary_matches_sales_report_service_for_today_and_excludes_other_days(): void
    {
        $this->travelTo(Carbon::create(2026, 7, 22, 10, 0, 0, 'Asia/Jakarta'));

        $product = Product::create(['name' => 'Kopi Susu', 'sell_price' => 15000]);
        $this->makeSale('2026-07-22', $product->id, 15000);
        $this->makeSale('2026-07-22', $product->id, 15000);
        // Kemarin -- tidak boleh ikut terhitung ke "hari ini".
        $this->makeSale('2026-07-21', $product->id, 15000);

        $token = $this->bearerToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/dashboard/today');

        $response->assertOk();
        $response->assertJsonPath('date', '2026-07-22');
        $response->assertJsonPath('transaction_count_today', 2);

        $expected = $this->reports->salesReport('2026-07-22', '2026-07-22');
        $this->assertSame(0, bccomp($response->json('omzet_today'), $expected['totals']['gross'], 4));

        $this->travelBack();
    }

    public function test_top_products_are_limited_to_five_sorted_by_gross_descending(): void
    {
        $this->travelTo(Carbon::create(2026, 7, 22, 10, 0, 0, 'Asia/Jakarta'));

        // 6 produk berbeda, omzet menurun -- hanya 5 teratas yang boleh muncul.
        for ($i = 1; $i <= 6; $i++) {
            $product = Product::create(['name' => "Produk {$i}", 'sell_price' => 1000 * (10 - $i)]);
            $this->makeSale('2026-07-22', $product->id, 1000 * (10 - $i));
        }

        $token = $this->bearerToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/dashboard/today');

        $response->assertOk();
        $topProducts = $response->json('top_products');
        $this->assertCount(5, $topProducts);

        $grossValues = array_map(fn ($row) => (float) $row['gross'], $topProducts);
        $sorted = $grossValues;
        rsort($sorted);
        $this->assertSame($sorted, $grossValues);

        $this->travelBack();
    }
}
