<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\FinancialReportService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penjualan yang dibatalkan (void) harus bersih di Laba Rugi -- baik
 * gabungan maupun yang difilter per cabang (jurnal pembalik bersumber
 * SaleVoid, bukan Sale).
 */
class SaleVoidReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_voided_sale_nets_to_zero_in_income_statement_per_outlet(): void
    {
        $this->seed(FoundationSeeder::class);
        $outlet = Outlet::first();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 5000]);
        $sales = app(SaleService::class);
        $make = fn (int $price) => $sales->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => '2026-11-23',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $price]],
        ]);
        $make(5000);
        $sales->voidSale($make(7000), 'Data demo', null);

        $reports = app(FinancialReportService::class);
        $all = $reports->incomeStatement('2026-11-01', '2026-11-30');
        $perOutlet = $reports->incomeStatement('2026-11-01', '2026-11-30', $outlet->id);

        $this->assertEquals(5000, (float) $all['total_revenue']);
        $this->assertEquals(5000, (float) $perOutlet['total_revenue']);
    }
}
