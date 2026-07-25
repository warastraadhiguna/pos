<?php

namespace Tests\Feature\Api;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use App\Services\SalesReportService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportControllerTest extends TestCase
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

        $this->sales = new SaleService(new InventoryService(), new PostingService(), new CashAccountService());
        $this->reports = new SalesReportService();

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
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
        $this->getJson('/api/v1/reports/sales')->assertUnauthorized();
    }

    public function test_report_for_an_explicit_range_matches_sales_report_service_exactly(): void
    {
        $product = Product::create(['name' => 'Kopi Susu', 'sell_price' => 15000]);
        $this->makeSale('2026-07-05', $product->id, 15000);
        $this->makeSale('2026-07-06', $product->id, 15000);
        // Di luar rentang -- tidak boleh ikut.
        $this->makeSale('2026-08-01', $product->id, 15000);

        $token = $this->bearerToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/reports/sales?start=2026-07-01&end=2026-07-31');

        $response->assertOk();

        $expected = $this->reports->salesReport('2026-07-01', '2026-07-31');
        $this->assertSame($expected['totals']['transaction_count'], $response->json('totals.transaction_count'));
        $this->assertSame(0, bccomp($response->json('totals.gross'), $expected['totals']['gross'], 4));
        $this->assertCount(2, $response->json('by_day'));
        $this->assertCount(1, $response->json('by_product'));
    }

    public function test_missing_date_params_default_to_the_current_month(): void
    {
        $token = $this->bearerToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/reports/sales');

        $response->assertOk();
        $response->assertJsonPath('start', now()->startOfMonth()->toDateString());
        $response->assertJsonPath('end', now()->toDateString());
    }
}
