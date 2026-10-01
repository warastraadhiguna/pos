<?php

namespace Tests\Feature;

use App\Models\ConsignmentAccrual;
use App\Models\ConsignmentPayment;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\CashAccountService;
use App\Services\ConsignmentPaymentService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pola PERSIS SupplierPaymentServiceTest -- dibuat sebagai file terpisah
 * (bukan reuse), konsisten dengan ConsignmentPaymentService sendiri yang
 * sengaja tidak reuse SupplierPaymentService (lihat rancangan).
 *
 * Accrual dibuat LANGSUNG via ConsignmentAccrual::create() (bukan lewat
 * SaleService sungguhan) -- cukup untuk menguji ConsignmentPaymentService
 * itu sendiri, yang tidak peduli bagaimana accrual-nya terbentuk. Sale
 * dibuat kosong (tanpa lines) murni untuk memenuhi FK sale_id.
 */
class ConsignmentPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConsignmentPaymentService $payments;

    private Outlet $outlet;

    private Supplier $supplier;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->payments = new ConsignmentPaymentService(new PostingService(), new CashAccountService());
        $this->outlet = Outlet::first();
        $this->supplier = Supplier::create(['name' => 'Pemilik Titipan '.(++self::$seq)]);
    }

    private function makeAccrual(string $amount): ConsignmentAccrual
    {
        $sale = Sale::create([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => \App\Models\Warehouse::first()->id,
            'local_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'date' => '2026-07-01',
            'occurred_at' => '2026-07-01',
            'payment_method' => 'cash',
            'cash_account_code' => '1-1000',
            'status' => 'completed',
            'subtotal' => $amount,
            'tax_total' => '0',
            'grand_total' => $amount,
        ]);

        return ConsignmentAccrual::create([
            'sale_id' => $sale->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-01',
            'amount' => $amount,
        ]);
    }

    public function test_recording_a_payment_posts_a_balanced_journal_debiting_hutang_konsinyasi_crediting_kas(): void
    {
        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 500000,
            'allocations' => [
                ['consignment_accrual_id' => null, 'amount' => 500000],
            ],
        ]);

        $this->assertInstanceOf(ConsignmentPayment::class, $payment);
        $this->assertSame(0, bccomp($payment->amount, '500000', 4));

        $journal = Journal::where('source_type', ConsignmentPayment::class)->where('source_id', $payment->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['2-3000']->debit, '500000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '500000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_recording_a_payment_rejects_allocations_that_do_not_sum_to_the_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 500000,
            'allocations' => [
                ['consignment_accrual_id' => null, 'amount' => 400000],
            ],
        ]);
    }

    public function test_manual_allocations_across_multiple_accruals_are_stored_correctly(): void
    {
        $accrualA = $this->makeAccrual('120000');
        $accrualB = $this->makeAccrual('180000');

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 300000,
            'allocations' => [
                ['consignment_accrual_id' => $accrualA->id, 'amount' => 120000],
                ['consignment_accrual_id' => $accrualB->id, 'amount' => 180000],
            ],
        ]);

        $this->assertSame(2, $payment->allocations()->count());
        $byAccrual = $payment->allocations()->get()->keyBy('consignment_accrual_id');
        $this->assertSame(0, bccomp($byAccrual[$accrualA->id]->amount, '120000', 4));
        $this->assertSame(0, bccomp($byAccrual[$accrualB->id]->amount, '180000', 4));
    }

    public function test_overpayment_beyond_allocated_accruals_is_recorded_as_a_null_accrual_allocation(): void
    {
        $accrual = $this->makeAccrual('200000');

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'allocations' => [
                ['consignment_accrual_id' => $accrual->id, 'amount' => 200000],
                ['consignment_accrual_id' => null, 'amount' => 50000],
            ],
        ]);

        $advance = $payment->allocations()->whereNull('consignment_accrual_id')->firstOrFail();
        $this->assertSame(0, bccomp($advance->amount, '50000', 4));
    }

    public function test_allocate_fifo_fills_oldest_accruals_first(): void
    {
        $accruals = [
            ['consignment_accrual_id' => 1, 'remaining' => '100000'],
            ['consignment_accrual_id' => 2, 'remaining' => '200000'],
            ['consignment_accrual_id' => 3, 'remaining' => '150000'],
        ];

        $allocations = $this->payments->allocateFifo($accruals, '250000');

        $this->assertCount(2, $allocations);
        $this->assertSame(1, $allocations[0]['consignment_accrual_id']);
        $this->assertSame(0, bccomp($allocations[0]['amount'], '100000', 4));
        $this->assertSame(2, $allocations[1]['consignment_accrual_id']);
        $this->assertSame(0, bccomp($allocations[1]['amount'], '150000', 4));
    }

    public function test_allocate_fifo_with_no_accruals_puts_everything_in_the_null_bucket(): void
    {
        $allocations = $this->payments->allocateFifo([], '75000');

        $this->assertCount(1, $allocations);
        $this->assertNull($allocations[0]['consignment_accrual_id']);
        $this->assertSame(0, bccomp($allocations[0]['amount'], '75000', 4));
    }
}
