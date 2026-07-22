<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\TaxReportService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $sales;

    private PurchaseService $purchases;

    private TaxReportService $reports;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Uom $pcs;

    private TaxRate $taxRate;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        // FoundationSeeder default (produksi): ppn_active=false. Test
        // class ini murni menguji laporan PPN, jadi butuh saklar aktif.
        CompanySetting::current()->update(['ppn_active' => true]);

        $inventory = new InventoryService();
        $posting = new PostingService();
        $cashAccounts = new CashAccountService();
        $this->sales = new SaleService($inventory, $posting, $cashAccounts);
        $this->purchases = new PurchaseService($inventory, $posting, $cashAccounts);
        $this->reports = new TaxReportService();

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
        $this->supplier = Supplier::create(['name' => 'Supplier PPN Test']);
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
    }

    public function test_ppn_report_sums_output_and_input_tax_and_excludes_transactions_outside_the_range(): void
    {
        // Rentang laporan: 2026-07-01 s/d 2026-07-31.
        // Tiga penjualan ber-PPN Rp8.000 (tax 792.7928 masing-masing, lihat
        // referensi angka di SaleServiceTest) tepat di kedua ujung rentang
        // dan di tengah — harus SEMUA ikut terhitung (whereBetween inklusif).
        $saleAtStart = $this->makeTaxedSale('2026-07-01');
        $saleInMiddle = $this->makeTaxedSale('2026-07-15');
        $saleAtEnd = $this->makeTaxedSale('2026-07-31');

        // Di luar rentang — TIDAK BOLEH ikut terhitung.
        $saleBeforeRange = $this->makeTaxedSale('2026-06-30');
        $saleAfterRange = $this->makeTaxedSale('2026-08-01');

        // Satu pembelian ber-PPN di dalam rentang: subtotal 1000, tax 110 (11%).
        $receiptInRange = $this->makePurchaseWithTax('2026-07-20', qty: 10, unitPrice: 100);

        // Pembelian di luar rentang — TIDAK BOLEH ikut terhitung.
        $this->makePurchaseWithTax('2026-08-05', qty: 10, unitPrice: 100);

        $report = $this->reports->ppnReport('2026-07-01', '2026-07-31');

        // --- Total ---
        // Output: 3 x 792.7928 = 2378.3784
        $this->assertSame(0, bccomp($report['output']['total'], '2378.3784', 4));
        // Input: 1 x 110
        $this->assertSame(0, bccomp($report['input']['total'], '110', 4));
        // Payable: 2378.3784 - 110 = 2268.3784 (positif, bukan lebih bayar).
        $this->assertSame(0, bccomp($report['total_payable'], '2268.3784', 4));
        $this->assertFalse($report['is_overpaid']);

        $this->assertSame('2-1100', $report['output']['account']['code']);
        $this->assertSame('1-1300', $report['input']['account']['code']);

        // --- Rincian: hanya transaksi DALAM rentang yang muncul ---
        $this->assertCount(3, $report['output']['details']);
        $this->assertCount(1, $report['input']['details']);

        $inRangeUuids = [$saleAtStart->local_uuid, $saleInMiddle->local_uuid, $saleAtEnd->local_uuid];
        $sourcesJoined = implode(' | ', array_column($report['output']['details'], 'source'));

        foreach ($report['output']['details'] as $row) {
            $this->assertSame(0, bccomp($row['amount'], '792.7928', 4));
            $this->assertStringContainsString('Penjualan', $row['source']);
        }
        // Ketiganya yang DALAM rentang harus muncul di rincian...
        foreach ($inRangeUuids as $uuid) {
            $this->assertStringContainsString($uuid, $sourcesJoined);
        }
        // ...dan tidak ada sale di luar rentang yang bocor ke rincian.
        $this->assertStringNotContainsString($saleBeforeRange->local_uuid, $sourcesJoined);
        $this->assertStringNotContainsString($saleAfterRange->local_uuid, $sourcesJoined);

        $purchaseDetail = $report['input']['details'][0];
        $this->assertSame(0, bccomp($purchaseDetail['amount'], '110', 4));
        $this->assertStringContainsString('Supplier PPN Test', $purchaseDetail['source']);
        $this->assertStringContainsString((string) $receiptInRange->purchase_order_id, $purchaseDetail['source']);
    }

    public function test_ppn_report_shows_overpaid_when_input_tax_exceeds_output_tax(): void
    {
        // Satu penjualan kecil (tax 792.7928) vs satu pembelian besar (tax 5000)
        // dalam rentang yang sama -> PPN Masukan > PPN Keluaran -> lebih bayar.
        $this->makeTaxedSale('2026-07-10');
        $this->makePurchaseWithTax('2026-07-12', qty: 10, unitPrice: 4545.4545);

        $report = $this->reports->ppnReport('2026-07-01', '2026-07-31');

        $this->assertSame(-1, bccomp($report['total_payable'], '0', 4));
        $this->assertTrue($report['is_overpaid']);
    }

    private function makeTaxedSale(string $date): \App\Models\Sale
    {
        $product = Product::create([
            'name' => 'Produk PPN '.$this->uniqueCode(),
            'sell_price' => 8000,
            'tax_rate_id' => $this->taxRate->id,
        ]);

        return $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]],
        ]);
    }

    private function makePurchaseWithTax(string $date, int $qty, float|string $unitPrice): \App\Models\GoodsReceipt
    {
        $item = \App\Models\Item::create([
            'sku' => $this->uniqueCode('ITEM'),
            'name' => 'Item PPN',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => \App\Models\Account::where('code', '1-1200')->firstOrFail()->id,
        ]);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => [
                [
                    'item_id' => $item->id,
                    'qty' => $qty,
                    'purchase_uom_id' => $this->pcs->id,
                    'unit_price' => $unitPrice,
                    'tax_rate_id' => $this->taxRate->id,
                ],
            ],
        ]);

        $poLine = $po->lines->first();

        return $this->purchases->receiveGoods($po, [$poLine->id => $qty], $date, 'credit');
    }

    private function uniqueCode(string $prefix = 'X'): string
    {
        return $prefix.'-'.(++self::$seq);
    }
}
