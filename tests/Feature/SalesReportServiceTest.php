<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use App\Services\SalesReportService;
use App\Services\TaxReportService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $sales;

    private SalesReportService $reports;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private TaxRate $taxRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        // FoundationSeeder default (produksi): ppn_active=false. Test
        // class ini butuh skenario produk kena pajak, jadi di-set eksplisit
        // — satu test (switch OFF) meng-override ini balik ke false sendiri.
        CompanySetting::current()->update(['ppn_active' => true]);

        $inventory = new InventoryService();
        $posting = new PostingService();
        $this->sales = new SaleService($inventory, $posting, new CashAccountService());
        $this->reports = new SalesReportService();

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
        $this->taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
    }

    public function test_report_computes_correct_day_and_product_rollups_and_totals(): void
    {
        $productA = Product::create(['name' => 'Produk Kena Pajak', 'sell_price' => 8000, 'tax_rate_id' => $this->taxRate->id]);
        $productB = Product::create(['name' => 'Produk Tanpa Pajak', 'sell_price' => 5000]);

        // D1: dua sale terpisah, keduanya produk A (kena pajak), qty 1 masing-masing.
        $this->makeSale('2026-07-05', [['product_id' => $productA->id, 'qty' => 1, 'unit_price' => 8000]]);
        $this->makeSale('2026-07-05', [['product_id' => $productA->id, 'qty' => 1, 'unit_price' => 8000]]);

        // D2: satu sale, produk B (tanpa pajak), qty 3.
        $this->makeSale('2026-07-06', [['product_id' => $productB->id, 'qty' => 3, 'unit_price' => 5000]]);

        $report = $this->reports->salesReport('2026-07-01', '2026-07-31');

        // --- Bagian A: per hari ---
        $this->assertCount(2, $report['by_day']);

        $day1 = collect($report['by_day'])->firstWhere('date', '2026-07-05');
        $this->assertSame(2, $day1['transaction_count']);
        $this->assertSame(0, bccomp($day1['gross'], '16000', 4));
        $this->assertSame(0, bccomp($day1['net'], '14414.4144', 4));
        $this->assertSame(0, bccomp($day1['tax'], '1585.5856', 4));
        // Konsistensi inclusive: net + tax harus eksak sama dengan gross.
        $this->assertSame(0, bccomp(bcadd($day1['net'], $day1['tax'], 4), $day1['gross'], 4));

        $day2 = collect($report['by_day'])->firstWhere('date', '2026-07-06');
        $this->assertSame(1, $day2['transaction_count']);
        $this->assertSame(0, bccomp($day2['gross'], '15000', 4));
        $this->assertSame(0, bccomp($day2['net'], '15000', 4));
        $this->assertSame(0, bccomp($day2['tax'], '0', 4));

        // --- Bagian B: per produk, urut gross desc (A=16000 > B=15000) ---
        $this->assertCount(2, $report['by_product']);
        $this->assertSame($productA->id, $report['by_product'][0]['product_id']);
        $this->assertSame(0, bccomp($report['by_product'][0]['qty'], '2', 4));
        $this->assertSame(0, bccomp($report['by_product'][0]['gross'], '16000', 4));
        $this->assertSame(0, bccomp($report['by_product'][0]['net'], '14414.4144', 4));
        $this->assertSame(0, bccomp($report['by_product'][0]['tax'], '1585.5856', 4));

        $this->assertSame($productB->id, $report['by_product'][1]['product_id']);
        $this->assertSame(0, bccomp($report['by_product'][1]['qty'], '3', 4));
        $this->assertSame(0, bccomp($report['by_product'][1]['gross'], '15000', 4));
        $this->assertSame(0, bccomp($report['by_product'][1]['net'], '15000', 4));
        $this->assertSame(0, bccomp($report['by_product'][1]['tax'], '0', 4));

        foreach ($report['by_product'] as $row) {
            $this->assertSame(0, bccomp(bcadd($row['net'], $row['tax'], 4), $row['gross'], 4));
        }

        // --- Totals: Bagian A dan Bagian B harus jumlah ke angka yang sama ---
        $this->assertSame(3, $report['totals']['transaction_count']);
        $this->assertSame(0, bccomp($report['totals']['gross'], '31000', 4));
        $this->assertSame(0, bccomp($report['totals']['net'], '29414.4144', 4));
        $this->assertSame(0, bccomp($report['totals']['tax'], '1585.5856', 4));

        $productTotalNet = collect($report['by_product'])->reduce(fn ($carry, $row) => bcadd($carry, $row['net'], 4), '0');
        $productTotalTax = collect($report['by_product'])->reduce(fn ($carry, $row) => bcadd($carry, $row['tax'], 4), '0');
        $this->assertSame(0, bccomp($productTotalNet, $report['totals']['net'], 4));
        $this->assertSame(0, bccomp($productTotalTax, $report['totals']['tax'], 4));
    }

    public function test_report_respects_period_boundaries_inclusive_on_both_ends(): void
    {
        $product = Product::create(['name' => 'Produk Batas', 'sell_price' => 10000]);

        $this->makeSale('2026-06-30', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]]); // sebelum rentang
        $this->makeSale('2026-07-01', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]]); // tepat di awal
        $this->makeSale('2026-07-15', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]]); // tengah
        $this->makeSale('2026-07-31', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]]); // tepat di akhir
        $this->makeSale('2026-08-01', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]]); // setelah rentang

        $report = $this->reports->salesReport('2026-07-01', '2026-07-31');

        $this->assertSame(3, $report['totals']['transaction_count']);
        $this->assertSame(0, bccomp($report['totals']['gross'], '30000', 4));
        $this->assertSame(0, bccomp($report['by_product'][0]['qty'], '3', 4));
    }

    public function test_report_reconciles_with_income_statement_and_ppn_report_for_the_same_range(): void
    {
        $taxed = Product::create(['name' => 'Produk Kena Pajak', 'sell_price' => 8000, 'tax_rate_id' => $this->taxRate->id]);
        $untaxed = Product::create(['name' => 'Produk Tanpa Pajak', 'sell_price' => 5000]);

        $this->makeSale('2026-07-05', [
            ['product_id' => $taxed->id, 'qty' => 2, 'unit_price' => 8000],
            ['product_id' => $untaxed->id, 'qty' => 1, 'unit_price' => 5000],
        ]);
        $this->makeSale('2026-07-20', [['product_id' => $taxed->id, 'qty' => 1, 'unit_price' => 8000]]);

        $salesReport = $this->reports->salesReport('2026-07-01', '2026-07-31');
        $incomeStatement = (new FinancialReportService())->incomeStatement('2026-07-01', '2026-07-31');
        $ppnReport = (new TaxReportService())->ppnReport('2026-07-01', '2026-07-31');

        // Bersih di laporan ini HARUS sama dengan akun Penjualan di Laba Rugi.
        $this->assertSame(0, bccomp($salesReport['totals']['net'], $incomeStatement['total_revenue'], 4));

        // PPN di laporan ini HARUS sama dengan PPN Keluaran di laporan PPN.
        $this->assertSame(0, bccomp($salesReport['totals']['tax'], $ppnReport['output']['total'], 4));
    }

    public function test_line_reconstruction_correctly_treats_lines_as_untaxed_when_ppn_switch_was_off_at_sale_time(): void
    {
        // Produk PUNYA tax_rate_id (nominal kena pajak), tapi saklar PPN
        // OFF saat sale ini dibuat — sale_lines.tax_rate_id tetap tersimpan
        // (mencerminkan tarif nominal produk), TAPI baris ini semestinya
        // TIDAK dianggap kena pajak saat direkonstruksi di laporan, persis
        // seperti SaleService tidak mengenakan pajak saat itu.
        CompanySetting::current()->update(['ppn_active' => false]);

        $product = Product::create(['name' => 'Produk Nominal Kena Pajak', 'sell_price' => 8000, 'tax_rate_id' => $this->taxRate->id]);
        $this->makeSale('2026-07-10', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]]);

        $report = $this->reports->salesReport('2026-07-01', '2026-07-31');

        $this->assertSame(0, bccomp($report['totals']['gross'], '8000', 4));
        $this->assertSame(0, bccomp($report['totals']['net'], '8000', 4));
        $this->assertSame(0, bccomp($report['totals']['tax'], '0', 4));
        $this->assertSame(0, bccomp($report['by_product'][0]['tax'], '0', 4));
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Laporan harian mengelompokkan `sales.date` langsung (lihat
     * SalesReportService::byDay()) -- ini menegaskan transaksi yang
     * dibuat lewat `now()` (jalur Kasir\SaleController) pada jam rawan
     * WIB 00:00-06:59 (= UTC hari SEBELUMNYA) masuk ke rollup hari WIB
     * yang benar, bukan tercecer ke hari sebelumnya di laporan.
     */
    public function test_daily_report_groups_a_dawn_wib_sale_into_the_correct_wib_day(): void
    {
        $product = Product::create(['name' => 'Produk Subuh', 'sell_price' => 8000]);

        // WIB 2026-07-19 03:00 == UTC 2026-07-18 20:00.
        Carbon::setTestNow(Carbon::create(2026, 7, 19, 3, 0, 0, 'Asia/Jakarta'));
        $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now(),
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]],
        ]);
        Carbon::setTestNow();

        // Sale pembanding di siang hari WIB 18 (hari sebelumnya, tidak ambigu).
        $this->makeSale('2026-07-18', [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]]);

        $report = $this->reports->salesReport('2026-07-18', '2026-07-19');

        $this->assertCount(2, $report['by_day'], 'Harus ada dua hari terpisah (18 dan 19), bukan digabung ke satu hari.');

        $day18 = collect($report['by_day'])->firstWhere('date', '2026-07-18');
        $day19 = collect($report['by_day'])->firstWhere('date', '2026-07-19');

        $this->assertNotNull($day19, 'Transaksi subuh WIB harus masuk rollup tanggal 19, bukan hilang/tercecer ke 18.');
        $this->assertSame(1, $day18['transaction_count']);
        $this->assertSame(1, $day19['transaction_count']);
    }
}
