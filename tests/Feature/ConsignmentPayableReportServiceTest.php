<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\CashAccountService;
use App\Services\ConsignmentPayableReportService;
use App\Services\ConsignmentPaymentService;
use App\Services\DraftSyncService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pola PERSIS SupplierPayableReportServiceTest -- accrual di sini dibuat
 * lewat SaleService SUNGGUHAN (bukan insert manual) supaya test ini juga
 * jadi bukti integrasi end-to-end: jual item konsinyasi -> accrual
 * tercatat -> totalOutstanding() rekonsiliasi dengan Neraca.
 */
class ConsignmentPayableReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $sales;

    private ConsignmentPaymentService $payments;

    private ConsignmentPayableReportService $payable;

    private FinancialReportService $financials;

    private InventoryService $inventory;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Uom $pcs;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->sales = new SaleService($this->inventory, new PostingService(), new CashAccountService(), new DraftSyncService(new BranchService()));
        $this->payments = new ConsignmentPaymentService(new PostingService(), new CashAccountService());
        $this->payable = new ConsignmentPayableReportService();
        $this->financials = new FinancialReportService();

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    private function sellConsignmentItem(Supplier $supplier, string $cost, string $sellPrice, string $date): void
    {
        $item = Item::create([
            'sku' => 'KONSI-'.(++self::$seq),
            'name' => 'Item Konsinyasi',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
            'is_consignment' => true,
            'consignment_supplier_id' => $supplier->id,
        ]);
        $product = Product::create(['name' => 'Produk Konsinyasi '.self::$seq, 'sell_price' => $sellPrice]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        $this->inventory->recordInbound($item, $this->warehouse, 100, $cost, Outlet::create(['name' => 'Opening '.(++self::$seq)]), $date);

        $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $sellPrice]],
        ]);
    }

    public function test_total_outstanding_reconciles_with_hutang_konsinyasi_balance_on_the_balance_sheet(): void
    {
        $supplierA = Supplier::create(['name' => 'Konsinyasi Reconcile A']);
        $supplierB = Supplier::create(['name' => 'Konsinyasi Reconcile B']);

        $this->sellConsignmentItem($supplierA, '5000', '10000', '2026-07-01'); // accrual 5,000
        $this->sellConsignmentItem($supplierB, '7000', '12000', '2026-07-02'); // accrual 7,000

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $supplierA->id,
            'date' => '2026-07-06',
            'amount' => 2000,
            'allocations' => [['consignment_accrual_id' => null, 'amount' => 2000]],
        ]);

        $asOf = '2026-07-31';
        $totalFromPayableReport = $this->payable->totalOutstanding($asOf);

        $balanceSheet = $this->financials->balanceSheet($asOf);
        $hutangRow = collect($balanceSheet['liabilities'])->firstWhere('code', '2-3000');

        $this->assertNotNull($hutangRow, 'Akun Hutang Konsinyasi harus muncul di Neraca setelah ada transaksi penjualan konsinyasi.');
        $this->assertSame(0, bccomp($totalFromPayableReport, $hutangRow['balance'], 4));
        // 5000 + 7000 - 2000 = 10000 -- jangkar tambahan supaya test ini
        // tidak lulus kebetulan walau rumusnya salah di kedua sisi.
        $this->assertSame(0, bccomp($totalFromPayableReport, '10000', 4));
    }

    public function test_outstanding_for_supplier_excludes_voided_accruals(): void
    {
        $supplier = Supplier::create(['name' => 'Konsinyasi Void Test']);
        $this->sellConsignmentItem($supplier, '5000', '10000', '2026-07-01');

        $sale = \App\Models\Sale::where('status', 'completed')->firstOrFail();
        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '5000', 4));

        $this->sales->voidSale($sale, 'batal uji', null);

        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '0', 4));
    }
}
