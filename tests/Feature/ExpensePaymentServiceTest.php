<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\ExpensePayableReportService;
use App\Services\ExpensePaymentService;
use App\Services\ExpenseService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ExpensePaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $expenses;

    private ExpensePaymentService $payments;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->expenses = new ExpenseService(new PostingService(), new CashAccountService());
        $this->payments = new ExpensePaymentService(new PostingService(), new ExpensePayableReportService(), new CashAccountService());
        $this->outlet = Outlet::first();
    }

    private function creditExpense(string $amount): Expense
    {
        return $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3100')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => $amount,
            'payment_method' => 'credit',
            'description' => 'Sewa bulan Juli',
        ]);
    }

    public function test_recording_a_payment_posts_a_balanced_journal_debiting_hutang_beban_crediting_kas(): void
    {
        $expense = $this->creditExpense('500000');

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 500000,
        ]);

        $this->assertInstanceOf(ExpensePayment::class, $payment);

        $journal = Journal::where('source_type', ExpensePayment::class)->where('source_id', $payment->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['2-2000']->debit, '500000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '500000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_recording_a_payment_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $expense = $this->creditExpense('500000');

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 500000,
            'cash_account_code' => '1-1100',
        ]);

        $this->assertSame('1-1100', $payment->cash_account_code);

        $journal = Journal::where('source_type', ExpensePayment::class)->where('source_id', $payment->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->credit, '500000', 4));
        $this->assertArrayNotHasKey('1-1000', $lines->all());
    }

    public function test_partial_payment_leaves_a_remaining_balance_with_status_sebagian(): void
    {
        $expense = $this->creditExpense('500000');

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 200000,
        ]);

        $status = (new ExpensePayableReportService())->expenseStatus($expense->fresh());
        $this->assertSame(0, bccomp($status['remaining'], '300000', 4));
        $this->assertSame('sebagian', $status['status']);
    }

    public function test_multiple_partial_payments_sum_correctly_until_lunas(): void
    {
        $expense = $this->creditExpense('500000');

        $this->payments->recordPayment(['outlet_id' => $this->outlet->id, 'expense_id' => $expense->id, 'date' => '2026-07-10', 'amount' => 200000]);
        $this->payments->recordPayment(['outlet_id' => $this->outlet->id, 'expense_id' => $expense->id, 'date' => '2026-07-15', 'amount' => 300000]);

        $status = (new ExpensePayableReportService())->expenseStatus($expense->fresh());
        $this->assertSame(0, bccomp($status['remaining'], '0', 4));
        $this->assertSame('lunas', $status['status']);
        $this->assertSame(2, ExpensePayment::where('expense_id', $expense->id)->count());
    }

    public function test_rejects_payment_exceeding_the_remaining_balance(): void
    {
        $expense = $this->creditExpense('500000');

        $this->expectException(InvalidArgumentException::class);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 600000,
        ]);
    }

    public function test_overpayment_attempt_creates_no_rows(): void
    {
        $expense = $this->creditExpense('500000');

        try {
            $this->payments->recordPayment([
                'outlet_id' => $this->outlet->id,
                'expense_id' => $expense->id,
                'date' => '2026-07-10',
                'amount' => 600000,
            ]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, ExpensePayment::count());
        $this->assertSame(1, Journal::where('source_type', Expense::class)->count());
        $this->assertSame(0, Journal::where('source_type', ExpensePayment::class)->count());
    }

    public function test_rejects_payment_for_a_cash_expense(): void
    {
        $expense = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Listrik tunai',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 50000,
        ]);
    }

    public function test_rejects_a_zero_amount(): void
    {
        $expense = $this->creditExpense('500000');

        $this->expectException(InvalidArgumentException::class);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 0,
        ]);
    }
}
