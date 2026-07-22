<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private PurchaseService $purchases;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Uom $pcs;

    private Uom $gr;

    private Uom $sak;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->purchases = new PurchaseService($this->inventory, new PostingService(), new CashAccountService());

        $this->warehouse = Warehouse::first();
        $this->supplier = Supplier::create(['name' => 'Supplier Test']);

        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->gr = Uom::where('code', 'GR')->firstOrFail();
        $this->sak = Uom::where('code', 'SAK')->firstOrFail();

        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    public function test_creating_a_purchase_order_does_not_touch_stock_or_the_ledger(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);
        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000, 'tax_rate_id' => $taxRate->id],
            ],
        ]);

        $this->assertSame('open', $po->status);
        $this->assertSame(1, $po->lines->count());
        $this->assertSame(0, bccomp($po->subtotal, '100000', 4));
        $this->assertSame(0, bccomp($po->tax_total, '11000', 4));
        $this->assertSame(0, bccomp($po->grand_total, '111000', 4));

        // PO belum menyentuh stok atau jurnal sama sekali.
        $this->assertSame('0.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, Journal::count());
    }

    public function test_purchase_order_notes_are_stored_and_default_to_null(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $poWithNotes = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
            'notes' => 'Kurir bilang sisa menyusul minggu depan.',
        ]);
        $this->assertSame('Kurir bilang sisa menyusul minggu depan.', $poWithNotes->fresh()->notes);

        $poWithoutNotes = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $this->assertNull($poWithoutNotes->fresh()->notes);
    }

    public function test_goods_receipt_notes_are_stored_and_default_to_null(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $receiptWithNotes = $this->purchases->receiveGoods($po, [$poLine->id => 5], '2026-07-05', 'credit', 'Barang sedikit penyok.');
        $this->assertSame('Barang sedikit penyok.', $receiptWithNotes->fresh()->notes);

        $receiptWithoutNotes = $this->purchases->receiveGoods($po, [$poLine->id => 5], '2026-07-06', 'credit');
        $this->assertNull($receiptWithoutNotes->fresh()->notes);
    }

    public function test_full_receipt_updates_stock_posts_balanced_journal_and_marks_po_received(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);
        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000, 'tax_rate_id' => $taxRate->id],
            ],
        ]);
        $poLine = $po->lines->first();

        $receipt = $this->purchases->receiveGoods($po, [$poLine->id => 100], '2026-07-05', 'credit');

        $this->assertSame('100.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('1000.0000', $this->inventory->currentAverageCost($item, $this->warehouse));
        $this->assertSame('received', $po->fresh()->status);

        $journal = Journal::where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1200']->debit, '100000', 4));
        $this->assertSame(0, bccomp($lines['1-1300']->debit, '11000', 4));
        $this->assertSame(0, bccomp($lines['2-1000']->credit, '111000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_cash_receipt_credits_kas_instead_of_hutang_usaha(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);
        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000, 'tax_rate_id' => $taxRate->id],
            ],
        ]);
        $poLine = $po->lines->first();

        $receipt = $this->purchases->receiveGoods($po, [$poLine->id => 100], '2026-07-05', 'cash');

        $this->assertSame('cash', $receipt->payment_method);

        $journal = Journal::where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        // Persediaan & PPN Masukan sama seperti kredit — cuma sisi kredit yang beda.
        $this->assertSame(0, bccomp($lines['1-1200']->debit, '100000', 4));
        $this->assertSame(0, bccomp($lines['1-1300']->debit, '11000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '111000', 4));
        $this->assertArrayNotHasKey('2-1000', $lines->all());

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_cash_receipt_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $receipt = $this->purchases->receiveGoods($po, [$poLine->id => 10], '2026-07-05', 'cash', null, '1-1100');

        $this->assertSame('1-1100', $receipt->cash_account_code);

        $journal = Journal::where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->credit, '10000', 4));
        $this->assertArrayNotHasKey('1-1000', $lines->all());
    }

    public function test_receive_goods_rejects_an_unknown_payment_method(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $this->expectException(\InvalidArgumentException::class);
        $this->purchases->receiveGoods($po, [$poLine->id => 100], '2026-07-05', 'bank_transfer');
    }

    public function test_uom_conversion_from_purchase_uom_to_base_uom_is_applied(): void
    {
        // Gula dijual per karung (SAK) tapi stoknya dilacak dalam gram (GR).
        // 1 karung = 25000 gram (seeded), @250000/karung -> 10/gram.
        $gula = $this->makeStockedItem('GULA', 'Gula', $this->gr, $this->sak);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $gula->id, 'qty' => 2, 'purchase_uom_id' => $this->sak->id, 'unit_price' => 250000],
            ],
        ]);
        $poLine = $po->lines->first();

        $this->purchases->receiveGoods($po, [$poLine->id => 2], '2026-07-05', 'credit');

        $this->assertSame('50000.0000', $this->inventory->currentStock($gula, $this->warehouse));
        $this->assertSame('10.0000', $this->inventory->currentAverageCost($gula, $this->warehouse));

        $grLine = GoodsReceiptLine::where('item_id', $gula->id)->firstOrFail();
        $this->assertSame(0, bccomp($grLine->qty, '50000', 4));
        $this->assertSame(0, bccomp($grLine->unit_cost, '10', 4));
    }

    public function test_partial_receipt_then_full_receipt_progresses_status_and_stock(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $this->purchases->receiveGoods($po, [$poLine->id => 60], '2026-07-05', 'credit');
        $this->assertSame('partial', $po->fresh()->status);
        $this->assertSame('60.0000', $this->inventory->currentStock($item, $this->warehouse));

        $this->purchases->receiveGoods($po, [$poLine->id => 40], '2026-07-06', 'credit');
        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame('100.0000', $this->inventory->currentStock($item, $this->warehouse));
    }

    public function test_two_po_lines_for_the_same_item_are_tracked_independently(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 50, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
                ['item_id' => $item->id, 'qty' => 30, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1200],
            ],
        ]);

        $this->assertSame(2, $po->lines->count());
        [$lineA, $lineB] = $po->lines->all();

        // Terima baris A (50 @1000) penuh — baris B belum disentuh sama sekali.
        $this->purchases->receiveGoods($po, [$lineA->id => 50], '2026-07-05', 'credit');

        $this->assertSame('partial', $po->fresh()->status);
        $this->assertSame('50.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame('1000.0000', $this->inventory->currentAverageCost($item, $this->warehouse));

        $receivedForLineA = $lineA->goodsReceiptLines()->get()
            ->reduce(fn ($carry, $grLine) => bcadd($carry, $grLine->qty, 4), '0');
        $receivedForLineB = $lineB->goodsReceiptLines()->get()
            ->reduce(fn ($carry, $grLine) => bcadd($carry, $grLine->qty, 4), '0');
        $this->assertSame(0, bccomp($receivedForLineA, '50', 4));
        $this->assertSame(0, bccomp($receivedForLineB, '0', 4));

        // Terima baris B (30 @1200) penuh — sekarang PO lengkap.
        $this->purchases->receiveGoods($po, [$lineB->id => 30], '2026-07-06', 'credit');

        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame('80.0000', $this->inventory->currentStock($item, $this->warehouse));
        // (50 x 1000 + 30 x 1200) / 80 = 1075
        $this->assertSame('1075.0000', $this->inventory->currentAverageCost($item, $this->warehouse));

        $receivedForLineB = $lineB->goodsReceiptLines()->get()
            ->reduce(fn ($carry, $grLine) => bcadd($carry, $grLine->qty, 4), '0');
        $this->assertSame(0, bccomp($receivedForLineB, '30', 4));
    }

    public function test_receive_goods_rejects_a_line_id_belonging_to_a_different_purchase_order(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $poA = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poB = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $lineFromPoB = $poB->lines->first();

        // Mencoba menerima PO A dengan line ID milik PO B harus ditolak,
        // bukan diam-diam diproses seolah-olah milik PO A.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->purchases->receiveGoods($poA, [$lineFromPoB->id => 10], '2026-07-05', 'credit');
    }

    public function test_a_line_with_unconvertible_uom_rolls_back_the_entire_receipt(): void
    {
        $goodItem = $this->makeStockedItem('WIDGET-OK', 'Widget OK', $this->pcs, $this->pcs);
        $badItem = $this->makeStockedItem('WIDGET-BAD', 'Widget Bad', $this->pcs, $this->pcs);
        $ml = Uom::where('code', 'ML')->firstOrFail();

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $goodItem->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
                // purchase_uom sengaja ML, padahal base_uom item PCS dan tidak ada
                // konversi ML -> PCS di FoundationSeeder.
                ['item_id' => $badItem->id, 'qty' => 10, 'purchase_uom_id' => $ml->id, 'unit_price' => 1000],
            ],
        ]);

        $goodLine = $po->lines()->where('item_id', $goodItem->id)->firstOrFail();
        $badLine = $po->lines()->where('item_id', $badItem->id)->firstOrFail();

        $stockMovementCountBefore = StockMovement::count();

        try {
            // Baris pertama (goodLine) valid dan akan berhasil diproses lebih dulu
            // (menulis stock_movement) sebelum baris kedua (badLine) gagal —
            // membuktikan langkah yang sudah "berhasil" pun ikut rollback.
            $this->purchases->receiveGoods($po, [
                $goodLine->id => 10,
                $badLine->id => 10,
            ], '2026-07-05', 'credit');

            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, GoodsReceiptLine::count());
        $this->assertSame(0, Journal::count());
        $this->assertSame($stockMovementCountBefore, StockMovement::count());
        $this->assertSame('open', $po->fresh()->status);
    }

    public function test_detect_over_receipts_is_empty_when_qty_is_within_the_remaining_order(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $this->assertSame(0, bccomp($this->purchases->remainingQtyInPurchaseUom($poLine), '100', 4));
        $this->assertSame([], $this->purchases->detectOverReceipts($po, [$poLine->id => 80]));
    }

    public function test_detect_over_receipts_flags_a_line_that_exceeds_the_remaining_order(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $overs = $this->purchases->detectOverReceipts($po, [$poLine->id => 80020]);

        $this->assertCount(1, $overs);
        $this->assertSame($poLine->id, $overs[0]['line']->id);
        $this->assertSame(0, bccomp($overs[0]['remaining'], '100', 4));
        $this->assertSame(0, bccomp($overs[0]['ordered'], '100', 4));
        $this->assertTrue($overs[0]['extreme']);

        // Cuma dideteksi, belum diproses — belum menyentuh stok/jurnal.
        $this->assertSame(0, StockMovement::count());
    }

    public function test_detect_over_receipts_is_not_extreme_when_just_slightly_over(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs, $this->pcs);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $overs = $this->purchases->detectOverReceipts($po, [$poLine->id => 105]);

        $this->assertCount(1, $overs);
        $this->assertFalse($overs[0]['extreme']);
    }

    public function test_remaining_qty_accounts_for_uom_conversion_and_prior_partial_receipts(): void
    {
        // Gula dijual per karung (SAK) tapi stoknya dilacak dalam gram (GR).
        $gula = $this->makeStockedItem('GULA', 'Gula', $this->gr, $this->sak);

        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $gula->id, 'qty' => 10, 'purchase_uom_id' => $this->sak->id, 'unit_price' => 250000],
            ],
        ]);
        $poLine = $po->lines->first();

        $this->purchases->receiveGoods($po, [$poLine->id => 4], '2026-07-05', 'credit');

        // Sisa dihitung dalam purchase_uom (SAK), bukan base_uom (GR).
        $this->assertSame(0, bccomp($this->purchases->remainingQtyInPurchaseUom($poLine->fresh()), '6', 4));
    }

    private function makeStockedItem(string $sku, string $name, Uom $baseUom, Uom $purchaseUom): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode($sku),
            'name' => $name,
            'costing_type' => 'stocked',
            'base_uom_id' => $baseUom->id,
            'purchase_uom_id' => $purchaseUom->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix.'-'.(++self::$seq);
    }
}
