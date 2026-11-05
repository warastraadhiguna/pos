<?php

namespace Tests\Feature;

use App\Exceptions\VoidConfirmationRequiredException;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use App\Services\StockOpnameService;
use App\Services\SupplierPayableReportService;
use App\Services\SupplierPaymentService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Pembatalan nota penerimaan barang & PO (PurchaseService::voidGoodsReceipt(),
 * cancelPurchaseOrder()) dan stock opname (StockOpnameService::cancelOpname()).
 */
class PurchaseVoidAndOpnameCancelTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private PurchaseService $purchases;

    private StockOpnameService $opnames;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Uom $pcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->purchases = new PurchaseService($this->inventory, new PostingService(), new CashAccountService());
        $this->opnames = new StockOpnameService($this->inventory, new PostingService());
        $this->warehouse = Warehouse::first();
        $this->supplier = Supplier::create(['name' => 'Supplier Test']);
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
    }

    public function test_voiding_a_receipt_pulls_stock_back_reverses_the_journal_and_reopens_the_po(): void
    {
        $item = $this->makeItem();
        $this->receiveIntoStock($item, 10, 1000, '2026-10-01'); // stok awal lain, rata-rata 1.000
        $receipt = $this->receive($item, 10, 2000, '2026-10-02', 'credit'); // rata-rata jadi 1.500

        $this->purchases->voidGoodsReceipt($receipt, 'salah input', null);

        $this->assertSame('10.0000', $this->inventory->currentStock($item, $this->warehouse));
        // Ditarik dengan harga nota (2.000), bukan rata-rata -> kembali 1.000.
        $this->assertSame('1000.0000', $this->inventory->currentAverageCost($item, $this->warehouse));

        $this->assertSame('0.0000', $this->accountNet('1-1200', GoodsReceipt::class, $receipt->id));
        $this->assertSame('0.0000', $this->accountNet('2-1000', GoodsReceipt::class, $receipt->id));

        $receipt->refresh();
        $this->assertNotNull($receipt->voided_at);
        $this->assertSame('salah input', $receipt->void_reason);
        $this->assertSame('open', $receipt->purchaseOrder->status);
        $this->assertSame('0', $this->purchases->receivedQtyInBaseUom($receipt->purchaseOrder->lines->first()));

        $payables = app(SupplierPayableReportService::class);
        $this->assertSame('0.0000', $payables->outstandingForSupplier($this->supplier->id));
        $this->assertSame([], $payables->notaBreakdownForSupplier($this->supplier->id));
    }

    public function test_voiding_a_paid_receipt_needs_confirmation_and_leaves_a_negative_payable(): void
    {
        $item = $this->makeItem();
        $receipt = $this->receive($item, 10, 1000, '2026-10-01', 'credit');

        (new SupplierPaymentService(new PostingService(), new CashAccountService()))->recordPayment([
            'outlet_id' => Outlet::first()->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-10-05',
            'amount' => 4000,
            'allocations' => [['goods_receipt_id' => $receipt->id, 'amount' => 4000]],
        ]);

        $this->assertSame('4000.0000', $this->purchases->voidWarnings($receipt)['paid']);

        try {
            $this->purchases->voidGoodsReceipt($receipt, 'retur', null);
            $this->fail('Seharusnya butuh konfirmasi.');
        } catch (VoidConfirmationRequiredException) {
            $this->assertNull($receipt->fresh()->voided_at);
            $this->assertSame('10.0000', $this->inventory->currentStock($item, $this->warehouse));
        }

        $this->purchases->voidGoodsReceipt($receipt, 'retur', null, confirmed: true);

        // Sudah bayar 4.000 untuk nota yang dibatalkan -> supplier berhutang 4.000.
        $outstanding = app(SupplierPayableReportService::class)->outstandingForSupplier($this->supplier->id);
        $this->assertSame('-4000.0000', $outstanding);
    }

    public function test_voiding_a_receipt_whose_goods_were_already_sold_needs_confirmation(): void
    {
        $item = $this->makeItem();
        $receipt = $this->receive($item, 10, 1000, '2026-10-01', 'cash');
        $this->inventory->recordOutbound($item, $this->warehouse, 7, $this->supplier, '2026-10-02');

        $warnings = $this->purchases->voidWarnings($receipt);
        $this->assertSame('3.0000', $warnings['short_stock'][0]['stock']);

        $this->expectException(VoidConfirmationRequiredException::class);
        $this->purchases->voidGoodsReceipt($receipt, 'salah supplier', null);
    }

    public function test_confirmed_void_of_sold_goods_goes_negative_at_the_receipt_cost(): void
    {
        $item = $this->makeItem();
        $receipt = $this->receive($item, 10, 1000, '2026-10-01', 'cash');
        $this->inventory->recordOutbound($item, $this->warehouse, 7, $this->supplier, '2026-10-02');

        $this->purchases->voidGoodsReceipt($receipt, 'salah supplier', null, confirmed: true);

        $this->assertSame('-7.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('1000.0000', $this->inventory->currentAverageCost($item, $this->warehouse));
        // Penerimaan & pembatalannya saling meniadakan di buku besar. (7 pcs
        // yang terjual di test ini lewat recordOutbound() mentah tanpa
        // jurnal; di penjualan sungguhan jurnal HPP-nya mengkredit 7.000,
        // sama dengan nilai kartu stok -7 x 1.000.)
        $this->assertSame('0.0000', $this->persediaanBalance());
    }

    public function test_purchase_order_can_only_be_cancelled_without_active_receipts(): void
    {
        $item = $this->makeItem();
        $receipt = $this->receive($item, 5, 1000, '2026-10-01', 'cash');
        $po = $receipt->purchaseOrder;

        try {
            $this->purchases->cancelPurchaseOrder($po, 'batal', null);
            $this->fail('PO dengan penerimaan aktif tidak boleh dibatalkan.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Batalkan penerimaannya dulu', $e->getMessage());
        }

        $this->purchases->voidGoodsReceipt($receipt, 'salah', null);
        $this->purchases->cancelPurchaseOrder($po, 'batal', null);

        $this->assertSame('cancelled', $po->fresh()->status);

        $this->expectException(RuntimeException::class);
        $this->purchases->receiveGoods($po->fresh(), [$po->lines->first()->id => 1], '2026-10-03', 'cash');
    }

    public function test_cancelling_a_draft_opname_only_changes_its_status(): void
    {
        $item = $this->makeItem();
        $opname = $this->opnames->startOpname(['warehouse_id' => $this->warehouse->id, 'date' => '2026-10-01', 'item_ids' => [$item->id]]);

        $this->opnames->cancelOpname($opname, null, null);

        $this->assertSame('cancelled', $opname->fresh()->status);
        $this->assertSame(0, JournalLine::count());
    }

    public function test_cancelling_a_posted_opname_reverses_its_shrinkage(): void
    {
        // Kasus Pocari: 43 tercatat hilang karena hitungan basi.
        $item = $this->makeItem();
        $this->receiveIntoStock($item, 62, 6745, '2026-10-01');
        $opname = $this->opnames->startOpname(['warehouse_id' => $this->warehouse->id, 'date' => '2026-10-05', 'item_ids' => [$item->id]]);
        $this->opnames->postOpname($opname, [$opname->lines->first()->id => 19], '2026-10-05');

        $this->opnames->cancelOpname($opname, 'hitungan basi', null);

        $this->assertSame('62.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('6745.0000', $this->inventory->currentAverageCost($item, $this->warehouse));
        $this->assertSame('0.0000', $this->accountNet('5-2000', $opname->getMorphClass(), $opname->id));
        $this->assertSame('cancelled', $opname->fresh()->status);
    }

    public function test_cancelling_a_posted_surplus_that_was_already_sold_needs_confirmation(): void
    {
        $item = $this->makeItem();
        $this->receiveIntoStock($item, 5, 1000, '2026-10-01');
        $opname = $this->opnames->startOpname(['warehouse_id' => $this->warehouse->id, 'date' => '2026-10-02', 'item_ids' => [$item->id]]);
        $this->opnames->postOpname($opname, [$opname->lines->first()->id => 15], '2026-10-02'); // +10
        $this->inventory->recordOutbound($item, $this->warehouse, 12, $this->supplier, '2026-10-03');

        $this->assertNotSame([], $this->opnames->cancelWarnings($opname->fresh()));

        try {
            $this->opnames->cancelOpname($opname, 'salah hitung', null);
            $this->fail('Seharusnya butuh konfirmasi.');
        } catch (VoidConfirmationRequiredException) {
            $this->assertSame('completed', $opname->fresh()->status);
        }

        $this->opnames->cancelOpname($opname, 'salah hitung', null, confirmed: true);
        $this->assertSame('-7.0000', $this->inventory->currentStock($item, $this->warehouse));
    }

    private function makeItem(): Item
    {
        return Item::create([
            'sku' => 'ITM-'.uniqid(),
            'name' => 'Barang Uji',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => Account::where('code', '1-1200')->firstOrFail()->id,
        ]);
    }

    private function receive(Item $item, int $qty, int $price, string $date, string $paymentMethod): GoodsReceipt
    {
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => [['item_id' => $item->id, 'qty' => $qty, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => $price]],
        ]);

        return $this->purchases->receiveGoods($po, [$po->lines->first()->id => $qty], $date, $paymentMethod);
    }

    private function receiveIntoStock(Item $item, int $qty, int $price, string $date): void
    {
        $this->receive($item, $qty, $price, $date, 'cash');
    }

    private function accountNet(string $code, string $sourceType, int $sourceId): string
    {
        $accountId = Account::where('code', $code)->firstOrFail()->id;
        $lines = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.source_type', $sourceType)
            ->where('journals.source_id', $sourceId)
            ->where('journal_lines.account_id', $accountId)
            ->get(['journal_lines.debit', 'journal_lines.credit']);

        return $lines->reduce(fn (string $carry, $line) => bcadd($carry, bcsub((string) $line->debit, (string) $line->credit, 4), 4), '0.0000');
    }

    private function persediaanBalance(): string
    {
        $accountId = Account::where('code', '1-1200')->firstOrFail()->id;

        return JournalLine::where('account_id', $accountId)->get()
            ->reduce(fn (string $carry, $line) => bcadd($carry, bcsub((string) $line->debit, (string) $line->credit, 4), 4), '0.0000');
    }
}
