<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use App\Services\SupplierPaymentService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SupplierPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $purchases;

    private SupplierPaymentService $payments;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Uom $pcs;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $this->payments = new SupplierPaymentService(new PostingService(), new CashAccountService());
        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
        $this->supplier = Supplier::create(['name' => 'Supplier Test']);
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    private function receiveOnCredit(string $qty, string $unitPrice): GoodsReceipt
    {
        $item = Item::create([
            'sku' => 'ITEM-'.(++self::$seq),
            'name' => 'Item '.self::$seq,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => $qty, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => $unitPrice],
            ],
        ]);
        $poLine = $po->lines->first();

        return $this->purchases->receiveGoods($po, [$poLine->id => $qty], '2026-07-01', 'credit');
    }

    public function test_recording_a_payment_posts_a_balanced_journal_debiting_hutang_crediting_kas(): void
    {
        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 500000,
            'allocations' => [
                ['goods_receipt_id' => null, 'amount' => 500000],
            ],
        ]);

        $this->assertInstanceOf(SupplierPayment::class, $payment);
        $this->assertSame(0, bccomp($payment->amount, '500000', 4));

        $journal = Journal::where('source_type', SupplierPayment::class)->where('source_id', $payment->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['2-1000']->debit, '500000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '500000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_recording_a_payment_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 300000,
            'cash_account_code' => '1-1100',
            'allocations' => [
                ['goods_receipt_id' => null, 'amount' => 300000],
            ],
        ]);

        $this->assertSame('1-1100', $payment->cash_account_code);

        $journal = Journal::where('source_type', SupplierPayment::class)->where('source_id', $payment->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->credit, '300000', 4));
        $this->assertArrayNotHasKey('1-1000', $lines->all());
    }

    // (a) Invarian SUM(allocations.amount) = payment.amount ditegakkan.
    public function test_recording_a_payment_rejects_allocations_that_do_not_sum_to_the_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 500000,
            'allocations' => [
                ['goods_receipt_id' => null, 'amount' => 400000],
            ],
        ]);
    }

    public function test_mismatched_allocations_do_not_create_any_rows(): void
    {
        try {
            $this->payments->recordPayment([
                'outlet_id' => $this->outlet->id,
                'supplier_id' => $this->supplier->id,
                'date' => '2026-07-10',
                'amount' => 500000,
                'allocations' => [
                    ['goods_receipt_id' => null, 'amount' => 100000],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, SupplierPayment::count());
        $this->assertSame(0, Journal::count());
    }

    // (c) Alokasi manual per nota tersimpan benar (beberapa nota sekaligus).
    public function test_manual_allocations_across_multiple_notas_are_stored_correctly(): void
    {
        $receiptA = $this->receiveOnCredit('10', '12000'); // 120,000
        $receiptB = $this->receiveOnCredit('10', '18000'); // 180,000

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 300000,
            'allocations' => [
                ['goods_receipt_id' => $receiptA->id, 'amount' => 120000],
                ['goods_receipt_id' => $receiptB->id, 'amount' => 180000],
            ],
        ]);

        $this->assertSame(2, $payment->allocations()->count());
        $byReceipt = $payment->allocations()->get()->keyBy('goods_receipt_id');
        $this->assertSame(0, bccomp($byReceipt[$receiptA->id]->amount, '120000', 4));
        $this->assertSame(0, bccomp($byReceipt[$receiptB->id]->amount, '180000', 4));
    }

    // (d) Kelebihan bayar -> alokasi goods_receipt_id NULL, bukan ditolak.
    public function test_overpayment_beyond_allocated_notas_is_recorded_as_a_null_receipt_allocation(): void
    {
        $receipt = $this->receiveOnCredit('10', '20000'); // 200,000

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'allocations' => [
                ['goods_receipt_id' => $receipt->id, 'amount' => 200000],
                ['goods_receipt_id' => null, 'amount' => 50000],
            ],
        ]);

        $advance = $payment->allocations()->whereNull('goods_receipt_id')->firstOrFail();
        $this->assertSame(0, bccomp($advance->amount, '50000', 4));
    }

    // (h) Jurnal tetap SATU per pembayaran, bukan per alokasi.
    public function test_one_journal_is_posted_per_payment_regardless_of_allocation_count(): void
    {
        $receiptA = $this->receiveOnCredit('10', '20000'); // 200,000
        $receiptB = $this->receiveOnCredit('10', '25000'); // 250,000

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 600000,
            'allocations' => [
                ['goods_receipt_id' => $receiptA->id, 'amount' => 200000],
                ['goods_receipt_id' => $receiptB->id, 'amount' => 250000],
                ['goods_receipt_id' => null, 'amount' => 150000],
            ],
        ]);

        $this->assertSame(3, $payment->allocations()->count());
        $this->assertSame(1, Journal::where('source_type', SupplierPayment::class)->where('source_id', $payment->id)->count());
    }

    public function test_memo_is_stored_when_provided(): void
    {
        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 100000,
            'memo' => 'Cicilan pertama',
            'allocations' => [
                ['goods_receipt_id' => null, 'amount' => 100000],
            ],
        ]);

        $this->assertSame('Cicilan pertama', $payment->fresh()->memo);
    }

    // (b) FIFO mengalokasikan ke nota tertua dulu dengan benar. Pure
    // function — tidak menyentuh DB, jadi id di sini tidak perlu nota
    // sungguhan (tidak ada FK yang tersentuh oleh allocateFifo() sendiri).
    public function test_allocate_fifo_fills_oldest_notas_first(): void
    {
        $notas = [
            ['goods_receipt_id' => 1, 'remaining' => '100000'],
            ['goods_receipt_id' => 2, 'remaining' => '200000'],
            ['goods_receipt_id' => 3, 'remaining' => '150000'],
        ];

        // Cukup untuk melunasi nota 1 penuh + sebagian nota 2.
        $allocations = $this->payments->allocateFifo($notas, '250000');

        $this->assertCount(2, $allocations);
        $this->assertSame(1, $allocations[0]['goods_receipt_id']);
        $this->assertSame(0, bccomp($allocations[0]['amount'], '100000', 4));
        $this->assertSame(2, $allocations[1]['goods_receipt_id']);
        $this->assertSame(0, bccomp($allocations[1]['amount'], '150000', 4));
    }

    public function test_allocate_fifo_skips_notas_already_fully_paid(): void
    {
        $notas = [
            ['goods_receipt_id' => 1, 'remaining' => '0'],
            ['goods_receipt_id' => 2, 'remaining' => '100000'],
        ];

        $allocations = $this->payments->allocateFifo($notas, '50000');

        $this->assertCount(1, $allocations);
        $this->assertSame(2, $allocations[0]['goods_receipt_id']);
        $this->assertSame(0, bccomp($allocations[0]['amount'], '50000', 4));
    }

    public function test_allocate_fifo_puts_leftover_beyond_all_notas_in_a_null_bucket(): void
    {
        $notas = [
            ['goods_receipt_id' => 1, 'remaining' => '100000'],
        ];

        $allocations = $this->payments->allocateFifo($notas, '150000');

        $this->assertCount(2, $allocations);
        $this->assertSame(1, $allocations[0]['goods_receipt_id']);
        $this->assertSame(0, bccomp($allocations[0]['amount'], '100000', 4));
        $this->assertNull($allocations[1]['goods_receipt_id']);
        $this->assertSame(0, bccomp($allocations[1]['amount'], '50000', 4));
    }

    public function test_allocate_fifo_with_no_notas_puts_everything_in_the_null_bucket(): void
    {
        $allocations = $this->payments->allocateFifo([], '75000');

        $this->assertCount(1, $allocations);
        $this->assertNull($allocations[0]['goods_receipt_id']);
        $this->assertSame(0, bccomp($allocations[0]['amount'], '75000', 4));
    }
}
