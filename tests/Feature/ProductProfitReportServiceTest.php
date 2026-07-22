<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\ProductProfitReportService;
use App\Services\SaleService;
use App\Services\SalesReportService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductProfitReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $sales;

    private ProductProfitReportService $reports;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Uom $pcs;

    private TaxRate $taxRate;

    private Account $persediaanAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        // FoundationSeeder default (produksi): ppn_active=false. Test
        // class ini butuh skenario produk kena pajak, jadi di-set eksplisit.
        CompanySetting::current()->update(['ppn_active' => true]);

        $this->sales = new SaleService(new InventoryService(), new PostingService(), new CashAccountService());
        $this->reports = new ProductProfitReportService();

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    public function test_report_computes_correct_net_hpp_gross_profit_and_margin_per_product(): void
    {
        // Produk A: kena pajak 11%, HPP 3000/unit, dijual 2x qty 1 di hari yang sama.
        $itemA = $this->makeCostOnlyItem('ITEM-A', 3000);
        $productA = $this->makeProduct('Produk A', 8000, $itemA, $this->taxRate->id);
        $this->makeSale('2026-07-05', [['product_id' => $productA->id, 'qty' => 1, 'unit_price' => 8000]]);
        $this->makeSale('2026-07-05', [['product_id' => $productA->id, 'qty' => 1, 'unit_price' => 8000]]);

        // Produk B: tanpa pajak, HPP 2000/unit, dijual qty 3 sekaligus.
        $itemB = $this->makeCostOnlyItem('ITEM-B', 2000);
        $productB = $this->makeProduct('Produk B', 5000, $itemB, null);
        $this->makeSale('2026-07-06', [['product_id' => $productB->id, 'qty' => 3, 'unit_price' => 5000]]);

        $report = $this->reports->productProfitReport('2026-07-01', '2026-07-31');

        $this->assertCount(2, $report['by_product']);

        // Diurutkan default (laba kotor desc): A (8414.4144) > B (9000.0000)?
        // B punya laba kotor lebih besar walau A duluan dibuat — buktikan urutan benar.
        $this->assertSame($productB->id, $report['by_product'][0]['product_id']);
        $this->assertSame($productA->id, $report['by_product'][1]['product_id']);

        $rowA = collect($report['by_product'])->firstWhere('product_id', $productA->id);
        $this->assertSame(0, bccomp($rowA['qty'], '2', 4));
        $this->assertSame(0, bccomp($rowA['net'], '14414.4144', 4));
        $this->assertSame(0, bccomp($rowA['hpp'], '6000', 4));
        $this->assertSame(0, bccomp($rowA['gross_profit'], '8414.4144', 4));
        $this->assertSame(0, bccomp($rowA['margin'], '58.3749', 4));

        $rowB = collect($report['by_product'])->firstWhere('product_id', $productB->id);
        $this->assertSame(0, bccomp($rowB['qty'], '3', 4));
        $this->assertSame(0, bccomp($rowB['net'], '15000', 4));
        $this->assertSame(0, bccomp($rowB['hpp'], '6000', 4));
        $this->assertSame(0, bccomp($rowB['gross_profit'], '9000', 4));
        $this->assertSame(0, bccomp($rowB['margin'], '60.0000', 4));

        $this->assertSame(0, bccomp($report['totals']['net'], '29414.4144', 4));
        $this->assertSame(0, bccomp($report['totals']['hpp'], '12000', 4));
        $this->assertSame(0, bccomp($report['totals']['gross_profit'], '17414.4144', 4));
        $this->assertSame(0, bccomp($report['totals']['margin'], '59.2036', 4));
    }

    public function test_report_can_be_sorted_by_margin_instead_of_gross_profit(): void
    {
        // Produk C: laba kotor kecil TAPI margin tinggi (harga rendah, HPP sangat rendah).
        $itemC = $this->makeCostOnlyItem('ITEM-C', 100);
        $productC = $this->makeProduct('Produk C', 1000, $itemC, null);
        $this->makeSale('2026-07-05', [['product_id' => $productC->id, 'qty' => 1, 'unit_price' => 1000]]);
        // gross_profit C = 900, margin = 90%

        // Produk D: laba kotor besar TAPI margin rendah (volume besar, margin tipis).
        $itemD = $this->makeCostOnlyItem('ITEM-D', 9000);
        $productD = $this->makeProduct('Produk D', 10000, $itemD, null);
        $this->makeSale('2026-07-05', [['product_id' => $productD->id, 'qty' => 10, 'unit_price' => 10000]]);
        // gross_profit D = 100000 - 90000 = 10000, margin = 10%

        $byGrossProfit = $this->reports->productProfitReport('2026-07-01', '2026-07-31', 'gross_profit');
        $this->assertSame($productD->id, $byGrossProfit['by_product'][0]['product_id']);

        $byMargin = $this->reports->productProfitReport('2026-07-01', '2026-07-31', 'margin');
        $this->assertSame($productC->id, $byMargin['by_product'][0]['product_id']);
    }

    public function test_report_respects_period_boundaries_inclusive_on_both_ends(): void
    {
        $item = $this->makeCostOnlyItem('ITEM-BATAS', 1000);
        $product = $this->makeProduct('Produk Batas', 5000, $item, null);

        $this->makeSale('2026-06-30', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]]); // sebelum
        $this->makeSale('2026-07-01', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]]); // awal
        $this->makeSale('2026-07-15', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]]); // tengah
        $this->makeSale('2026-07-31', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]]); // akhir
        $this->makeSale('2026-08-01', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]]); // setelah

        $report = $this->reports->productProfitReport('2026-07-01', '2026-07-31');

        $this->assertCount(1, $report['by_product']);
        $this->assertSame(0, bccomp($report['by_product'][0]['qty'], '3', 4));
        $this->assertSame(0, bccomp($report['totals']['net'], '15000', 4));
        $this->assertSame(0, bccomp($report['totals']['hpp'], '3000', 4));
    }

    public function test_report_reconciles_net_with_sales_report_and_hpp_with_income_statement_for_the_same_range(): void
    {
        $taxed = $this->makeCostOnlyItem('ITEM-TAXED', 4000);
        $taxedProduct = $this->makeProduct('Produk Kena Pajak', 8000, $taxed, $this->taxRate->id);
        $untaxed = $this->makeCostOnlyItem('ITEM-UNTAXED', 1500);
        $untaxedProduct = $this->makeProduct('Produk Tanpa Pajak', 5000, $untaxed, null);

        $this->makeSale('2026-07-05', [
            ['product_id' => $taxedProduct->id, 'qty' => 2, 'unit_price' => 8000],
            ['product_id' => $untaxedProduct->id, 'qty' => 1, 'unit_price' => 5000],
        ]);
        $this->makeSale('2026-07-20', [['product_id' => $taxedProduct->id, 'qty' => 1, 'unit_price' => 8000]]);

        $profitReport = $this->reports->productProfitReport('2026-07-01', '2026-07-31');
        $salesReport = (new SalesReportService())->salesReport('2026-07-01', '2026-07-31');
        $incomeStatement = (new FinancialReportService())->incomeStatement('2026-07-01', '2026-07-31');

        // Total bersih laporan ini HARUS sama dengan total bersih Laporan Penjualan
        // untuk periode yang sama — keduanya membaca sale_lines dengan rumus yang
        // identik, jadi ini seharusnya cocok BY CONSTRUCTION.
        $this->assertSame(0, bccomp($profitReport['totals']['net'], $salesReport['totals']['net'], 4));

        // Total HPP laporan ini HARUS sama dengan saldo akun HPP (5-1000) di Laba
        // Rugi untuk periode yang sama — keduanya berasal dari hpp_total yang sama
        // persis yang diposting SaleService::postSaleJournal().
        $hppAccountBalance = collect($incomeStatement['expenses'])->firstWhere('code', '5-1000')['balance'];
        $this->assertSame(0, bccomp($profitReport['totals']['hpp'], $hppAccountBalance, 4));
    }

    public function test_report_uses_the_hpp_locked_in_at_sale_time_not_a_recomputed_current_cost(): void
    {
        $item = $this->makeCostOnlyItem('ITEM-HISTORIS', 2000);
        $product = $this->makeProduct('Produk Historis', 8000, $item, null);

        $this->makeSale('2026-07-05', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]]);
        // hpp_total baris ini terkunci di 2000 saat transaksi terjadi.

        // Harga beli/standard_cost item berubah SETELAH transaksi terjadi.
        $item->update(['standard_cost' => 9999]);

        $report = $this->reports->productProfitReport('2026-07-01', '2026-07-31');

        // Laporan harus tetap memakai HPP 2000 (historis), bukan 9999 (harga baru).
        $this->assertSame(0, bccomp($report['totals']['hpp'], '2000', 4));
        $this->assertSame(0, bccomp($report['by_product'][0]['gross_profit'], '6000', 4));
    }

    private function makeSale(string $date, array $lines): void
    {
        $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => $lines,
        ]);
    }

    private function makeProduct(string $name, string $sellPrice, Item $componentItem, ?int $taxRateId): Product
    {
        $product = Product::create(['name' => $name, 'sell_price' => $sellPrice, 'tax_rate_id' => $taxRateId]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $componentItem->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        return $product;
    }

    private function makeCostOnlyItem(string $sku, string $standardCost): Item
    {
        return Item::create([
            'sku' => $sku.'-'.uniqid(),
            'name' => $sku,
            'costing_type' => 'cost_only',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => $standardCost,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }
}
