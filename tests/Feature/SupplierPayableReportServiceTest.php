<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Journal;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use App\Services\SupplierPayableReportService;
use App\Services\SupplierPaymentService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierPayableReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $purchases;

    private SupplierPaymentService $payments;

    private SupplierPayableReportService $payable;

    private FinancialReportService $financials;

    private Warehouse $warehouse;

    private Outlet $outlet;

    private Uom $pcs;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $this->payments = new SupplierPaymentService(new PostingService(), new CashAccountService());
        $this->payable = new SupplierPayableReportService();
        $this->financials = new FinancialReportService();

        $this->warehouse = Warehouse::first();
        $this->outlet = Outlet::first();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    private function makeItem(): Item
    {
        return Item::create([
            'sku' => 'ITEM-'.(++self::$seq),
            'name' => 'Item '.self::$seq,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function receiveOnCredit(Supplier $supplier, string $date, string $qty, string $unitPrice): GoodsReceipt
    {
        $item = $this->makeItem();
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => [
                ['item_id' => $item->id, 'qty' => $qty, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => $unitPrice],
            ],
        ]);
        $poLine = $po->lines->first();

        return $this->purchases->receiveGoods($po, [$poLine->id => $qty], $date, 'credit');
    }

    public function test_outstanding_reflects_a_single_credit_receipt_with_no_payments_yet(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier A']);
        $this->receiveOnCredit($supplier, '2026-07-01', '100', '1000');

        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '100000', 4));
    }

    public function test_partial_payments_reduce_outstanding_balance_correctly(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier B']);
        $this->receiveOnCredit($supplier, '2026-07-01', '100', '10000'); // hutang = 1,000,000

        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '1000000', 4));

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $supplier->id,
            'date' => '2026-07-05',
            'amount' => 400000,
            'allocations' => [['goods_receipt_id' => null, 'amount' => 400000]],
        ]);
        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '600000', 4));

        // Cicilan kedua melunasi sisanya persis.
        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $supplier->id,
            'date' => '2026-07-10',
            'amount' => 600000,
            'allocations' => [['goods_receipt_id' => null, 'amount' => 600000]],
        ]);
        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '0', 4));
    }

    public function test_cash_receipts_never_appear_in_the_payable_report(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier Cash']);
        $item = $this->makeItem();
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 50, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 2000],
            ],
        ]);
        $poLine = $po->lines->first();
        $this->purchases->receiveGoods($po, [$poLine->id => 50], '2026-07-01', 'cash');

        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '0', 4));

        $bySupplier = collect($this->payable->outstandingBySupplier())->firstWhere('supplier_id', $supplier->id);
        $this->assertSame(0, bccomp($bySupplier['total_credit'], '0', 4));
        $this->assertSame(0, bccomp($bySupplier['outstanding'], '0', 4));
    }

    public function test_mixed_cash_and_credit_receipts_only_count_the_credit_portion(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier Mixed']);

        // Kredit: 200.000. Tunai: 300.000 (tidak boleh ikut menambah hutang).
        $this->receiveOnCredit($supplier, '2026-07-01', '20', '10000');

        $item = $this->makeItem();
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-02',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 30, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 10000],
            ],
        ]);
        $poLine = $po->lines->first();
        $this->purchases->receiveGoods($po, [$poLine->id => 30], '2026-07-02', 'cash');

        $this->assertSame(0, bccomp($this->payable->outstandingForSupplier($supplier->id), '200000', 4));
    }

    public function test_total_outstanding_reconciles_with_hutang_usaha_balance_on_the_balance_sheet(): void
    {
        $supplierA = Supplier::create(['name' => 'Supplier Reconcile A']);
        $supplierB = Supplier::create(['name' => 'Supplier Reconcile B']);

        $this->receiveOnCredit($supplierA, '2026-07-01', '100', '5000'); // 500,000
        $this->receiveOnCredit($supplierB, '2026-07-02', '40', '25000'); // 1,000,000

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $supplierA->id,
            'date' => '2026-07-06',
            'amount' => 150000,
            'allocations' => [['goods_receipt_id' => null, 'amount' => 150000]],
        ]);

        // Tunai tidak boleh ikut mempengaruhi 2-1000 sama sekali — dicampur
        // di sini untuk membuktikan rekonsiliasi tetap tepat walau ada
        // transaksi tunai yang berjalan bersamaan.
        $item = $this->makeItem();
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $supplierA->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-07',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 5, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 20000],
            ],
        ]);
        $poLine = $po->lines->first();
        $this->purchases->receiveGoods($po, [$poLine->id => 5], '2026-07-07', 'cash');

        $asOf = '2026-07-31';
        $totalFromPayableReport = $this->payable->totalOutstanding($asOf);

        $balanceSheet = $this->financials->balanceSheet($asOf);
        $hutangRow = collect($balanceSheet['liabilities'])->firstWhere('code', '2-1000');

        $this->assertNotNull($hutangRow, 'Akun Hutang Usaha harus muncul di Neraca setelah ada transaksi kredit.');
        $this->assertSame(0, bccomp($totalFromPayableReport, $hutangRow['balance'], 4));
        // Nilai konkret sebagai jangkar tambahan supaya test ini tidak lulus
        // secara kebetulan kalau kedua sisi sama-sama nol.
        $this->assertSame(0, bccomp($totalFromPayableReport, '1350000', 4));
    }

    public function test_legacy_goods_receipt_rows_are_backfilled_to_credit_by_the_migration_default(): void
    {
        // Simulasikan baris "lama" (sebelum kolom payment_method ada) dengan
        // insert langsung tanpa menyebut kolomnya sama sekali — memastikan
        // DEFAULT 'credit' dari migrasi berlaku, bukan cuma saat diisi lewat
        // Eloquent.
        $supplier = Supplier::create(['name' => 'Supplier Legacy']);
        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-06-01',
            'status' => 'open',
            'subtotal' => '0',
            'tax_total' => '0',
            'grand_total' => '0',
        ]);

        $id = DB::table('goods_receipts')->insertGetId([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receipt = GoodsReceipt::findOrFail($id);
        $this->assertSame('credit', $receipt->payment_method);
    }

    // (e) Status per nota (Lunas/Sebagian/Belum), termasuk setelah cicilan bertahap.
    public function test_nota_status_progresses_from_belum_to_sebagian_to_lunas_across_partial_payments(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier Cicilan']);
        $receipt = $this->receiveOnCredit($supplier, '2026-07-01', '10', '10000'); // nota = 100,000

        $status = $this->payable->notaStatus($receipt->fresh());
        $this->assertSame('belum', $status['status']);
        $this->assertSame(0, bccomp($status['remaining'], '100000', 4));

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $supplier->id,
            'date' => '2026-07-05',
            'amount' => 40000,
            'allocations' => [['goods_receipt_id' => $receipt->id, 'amount' => 40000]],
        ]);
        $status = $this->payable->notaStatus($receipt->fresh());
        $this->assertSame('sebagian', $status['status']);
        $this->assertSame(0, bccomp($status['remaining'], '60000', 4));

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $supplier->id,
            'date' => '2026-07-10',
            'amount' => 60000,
            'allocations' => [['goods_receipt_id' => $receipt->id, 'amount' => 60000]],
        ]);
        $status = $this->payable->notaStatus($receipt->fresh());
        $this->assertSame('lunas', $status['status']);
        $this->assertSame(0, bccomp($status['remaining'], '0', 4));
    }

    public function test_nota_breakdown_orders_notas_oldest_first_for_fifo(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier Urutan']);
        $newer = $this->receiveOnCredit($supplier, '2026-07-10', '10', '1000');
        $older = $this->receiveOnCredit($supplier, '2026-07-01', '10', '1000');

        $breakdown = $this->payable->notaBreakdownForSupplier($supplier->id);

        $this->assertSame($older->id, $breakdown[0]['goods_receipt_id']);
        $this->assertSame($newer->id, $breakdown[1]['goods_receipt_id']);
    }

    // (f) Nota tunai tidak pernah punya status hutang.
    public function test_cash_nota_status_is_always_tunai_never_a_payable_status(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier Tunai Status']);
        $item = $this->makeItem();
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 5000],
            ],
        ]);
        $poLine = $po->lines->first();
        $receipt = $this->purchases->receiveGoods($po, [$poLine->id => 10], '2026-07-01', 'cash');

        $status = $this->payable->notaStatus($receipt->fresh());
        $this->assertSame('tunai', $status['status']);
        $this->assertSame(0, bccomp($status['nota_total'], '0', 4));
        $this->assertSame(0, bccomp($status['remaining'], '0', 4));

        // Nota tunai tidak pernah masuk daftar nota kredit yang perlu dilunasi.
        $breakdown = $this->payable->notaBreakdownForSupplier($supplier->id);
        $this->assertEmpty($breakdown);
    }

    public function test_recording_a_new_payment_does_not_alter_an_existing_journals_lines(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier Immutable']);
        $receipt = $this->receiveOnCredit($supplier, '2026-07-01', '10', '10000');

        $journal = Journal::where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)->firstOrFail();
        $originalLines = $journal->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray();

        // Aksi baru yang sama sekali tidak berkaitan.
        $otherSupplier = Supplier::create(['name' => 'Supplier Other']);
        $this->receiveOnCredit($otherSupplier, '2026-07-02', '5', '5000');
        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $otherSupplier->id,
            'date' => '2026-07-03',
            'amount' => 10000,
            'allocations' => [['goods_receipt_id' => null, 'amount' => 10000]],
        ]);

        $journalAfter = Journal::where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)->firstOrFail();
        $linesAfter = $journalAfter->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray();

        $this->assertSame($originalLines, $linesAfter);
    }
}
