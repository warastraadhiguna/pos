<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\ExpensePayableReportService;
use App\Services\ExpensePaymentService;
use App\Services\ExpenseService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpensePayableReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $expenses;

    private ExpensePaymentService $payments;

    private ExpensePayableReportService $report;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->expenses = new ExpenseService(new PostingService(), new CashAccountService());
        $this->report = new ExpensePayableReportService();
        $this->payments = new ExpensePaymentService(new PostingService(), $this->report, new CashAccountService());
        $this->outlet = Outlet::first();
    }

    public function test_unpaid_expenses_lists_only_credit_expenses_with_a_remaining_balance(): void
    {
        $cash = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Listrik tunai',
        ]);

        $credit = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3100')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 500000,
            'payment_method' => 'credit',
            'description' => 'Sewa Juli',
        ]);

        $ids = collect($this->report->unpaidExpenses())->pluck('expense_id');

        $this->assertTrue($ids->contains($credit->id));
        $this->assertFalse($ids->contains($cash->id));
    }

    public function test_fully_paid_expenses_disappear_from_the_unpaid_list(): void
    {
        $credit = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3100')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 500000,
            'payment_method' => 'credit',
            'description' => 'Sewa Juli',
        ]);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $credit->id,
            'date' => '2026-07-10',
            'amount' => 500000,
        ]);

        $ids = collect($this->report->unpaidExpenses())->pluck('expense_id');
        $this->assertFalse($ids->contains($credit->id));
    }

    public function test_total_outstanding_sums_remaining_across_multiple_expenses(): void
    {
        $first = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3100')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 500000,
            'payment_method' => 'credit',
            'description' => 'Sewa Juli',
        ]);
        $second = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3200')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 1000000,
            'payment_method' => 'credit',
            'description' => 'Gaji Juli',
        ]);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'expense_id' => $first->id,
            'date' => '2026-07-10',
            'amount' => 200000,
        ]);

        // Sisa: (500000 - 200000) + 1000000 = 1300000.
        $this->assertSame(0, bccomp($this->report->totalOutstanding(), '1300000', 4));
    }

    public function test_cash_expense_status_is_always_tunai_with_zero_remaining(): void
    {
        $cash = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Listrik tunai',
        ]);

        $status = $this->report->expenseStatus($cash->fresh());
        $this->assertSame('tunai', $status['status']);
        $this->assertSame(0, bccomp($status['remaining'], '0', 4));
    }

    public function test_status_transitions_from_belum_to_sebagian_to_lunas(): void
    {
        $expense = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3100')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 500000,
            'payment_method' => 'credit',
            'description' => 'Sewa Juli',
        ]);

        $this->assertSame('belum', $this->report->expenseStatus($expense->fresh())['status']);

        $this->payments->recordPayment(['outlet_id' => $this->outlet->id, 'expense_id' => $expense->id, 'date' => '2026-07-10', 'amount' => 200000]);
        $this->assertSame('sebagian', $this->report->expenseStatus($expense->fresh())['status']);

        $this->payments->recordPayment(['outlet_id' => $this->outlet->id, 'expense_id' => $expense->id, 'date' => '2026-07-15', 'amount' => 300000]);
        $this->assertSame('lunas', $this->report->expenseStatus($expense->fresh())['status']);
    }
}
