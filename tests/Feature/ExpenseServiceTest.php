<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\ExpenseService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $expenses;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->expenses = new ExpenseService(new PostingService(), new CashAccountService());
        $this->outlet = Outlet::first();
    }

    private function listrikAccount(): Account
    {
        return Account::where('code', '5-3000')->firstOrFail();
    }

    public function test_recording_a_cash_expense_posts_a_balanced_journal_debiting_expense_crediting_kas(): void
    {
        $expense = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => $this->listrikAccount()->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'payment_method' => 'cash',
            'description' => 'Listrik bulan Juli',
        ]);

        $this->assertInstanceOf(Expense::class, $expense);
        $this->assertSame(0, bccomp($expense->amount, '250000', 4));

        $journal = Journal::where('source_type', Expense::class)->where('source_id', $expense->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['5-3000']->debit, '250000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '250000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_recording_a_cash_expense_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $expense = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => $this->listrikAccount()->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'payment_method' => 'cash',
            'cash_account_code' => '1-1100',
            'description' => 'Listrik dibayar via bank',
        ]);

        $this->assertSame('1-1100', $expense->cash_account_code);

        $journal = Journal::where('source_type', Expense::class)->where('source_id', $expense->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->credit, '250000', 4));
        $this->assertFalse($lines->has('1-1000'));
    }

    public function test_recording_a_cash_expense_rejects_an_invalid_cash_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => $this->listrikAccount()->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'payment_method' => 'cash',
            'cash_account_code' => '1-1200', // Persediaan, bukan akun kas/bank
            'description' => 'Salah akun kas',
        ]);
    }

    public function test_recording_a_credit_expense_posts_a_balanced_journal_debiting_expense_crediting_hutang_beban(): void
    {
        $expense = $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => $this->listrikAccount()->id,
            'date' => '2026-07-10',
            'amount' => 500000,
            'payment_method' => 'credit',
            'description' => 'Sewa bulan Juli',
        ]);

        $journal = Journal::where('source_type', Expense::class)->where('source_id', $expense->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['5-3000']->debit, '500000', 4));
        $this->assertSame(0, bccomp($lines['2-2000']->credit, '500000', 4));
        // Hutang Beban terpisah dari Hutang Usaha -- tidak boleh menyentuh 2-1000 sama sekali.
        $this->assertFalse($lines->has('2-1000'));
    }

    public function test_recording_expense_rejects_a_non_expense_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '1-1000')->firstOrFail()->id,
            'date' => '2026-07-10',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Salah akun',
        ]);
    }

    public function test_recording_expense_rejects_the_reserved_hpp_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-1000')->firstOrFail()->id,
            'date' => '2026-07-10',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Tidak boleh manual ke HPP',
        ]);
    }

    public function test_recording_expense_rejects_the_reserved_selisih_persediaan_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-2000')->firstOrFail()->id,
            'date' => '2026-07-10',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Tidak boleh manual ke Selisih Persediaan',
        ]);
    }

    public function test_recording_expense_rejects_an_inactive_account(): void
    {
        $account = $this->listrikAccount();
        $account->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);

        $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => $account->id,
            'date' => '2026-07-10',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Akun nonaktif',
        ]);
    }

    public function test_recording_expense_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => $this->listrikAccount()->id,
            'date' => '2026-07-10',
            'amount' => 0,
            'payment_method' => 'cash',
            'description' => 'Nol',
        ]);
    }

    // Prinsip #5: alur multi-langkah gagal -> tidak ada baris yang tersisa.
    public function test_invalid_expense_does_not_create_any_rows(): void
    {
        try {
            $this->expenses->recordExpense([
                'outlet_id' => $this->outlet->id,
                'expense_account_id' => Account::where('code', '1-1000')->firstOrFail()->id,
                'date' => '2026-07-10',
                'amount' => 100000,
                'payment_method' => 'cash',
                'description' => 'Salah akun',
            ]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Journal::count());
    }

    public function test_selectable_expense_accounts_excludes_reserved_and_inactive_accounts(): void
    {
        Account::where('code', '5-3900')->firstOrFail()->update(['is_active' => false]);

        $codes = collect($this->expenses->selectableExpenseAccounts())->pluck('code')->all();

        $this->assertContains('5-3000', $codes);
        $this->assertContains('5-3100', $codes);
        $this->assertContains('5-3200', $codes);
        $this->assertNotContains('5-3900', $codes); // dinonaktifkan
        $this->assertNotContains('5-1000', $codes); // reserved
        $this->assertNotContains('5-2000', $codes); // reserved
    }

    public function test_create_expense_account_requires_the_5_3_code_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expenses->createExpenseAccount('5-1500', 'Salah Rentang');
    }

    public function test_create_expense_account_succeeds_with_a_valid_5_3_code(): void
    {
        $account = $this->expenses->createExpenseAccount('5-3300', 'Beban Internet');

        $this->assertSame('5-3300', $account->code);
        $this->assertSame('expense', $account->type);
        $this->assertTrue($account->is_active);
    }

    public function test_set_expense_account_active_toggles_and_persists(): void
    {
        $account = $this->listrikAccount();

        $this->expenses->setExpenseAccountActive($account, false);
        $this->assertFalse($account->fresh()->is_active);

        $this->expenses->setExpenseAccountActive($account, true);
        $this->assertTrue($account->fresh()->is_active);
    }
}
