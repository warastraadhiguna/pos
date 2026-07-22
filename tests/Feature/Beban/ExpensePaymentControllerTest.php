<?php

namespace Tests\Feature\Beban;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CashAccountService;
use App\Services\ExpenseService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpensePaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private ExpenseService $expenses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->outlet = Outlet::first();
        $this->expenses = new ExpenseService(new PostingService(), new CashAccountService());
    }

    private function actingAsAuthorizedUser(): User
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'beban.manage', 'label' => 'Beban', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    private function creditExpense(string $amount): Expense
    {
        return $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3100')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => $amount,
            'payment_method' => 'credit',
            'description' => 'Sewa Juli',
        ]);
    }

    public function test_index_lists_only_unpaid_credit_expenses(): void
    {
        $this->actingAsAuthorizedUser();
        $credit = $this->creditExpense('500000');
        $this->expenses->recordExpense([
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-07-01',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Listrik tunai',
        ]);

        $response = $this->get(route('beban.payments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Beban/Payments/Index')
            ->where('unpaidExpenses', fn ($expenses) => collect($expenses)->pluck('expense_id')->contains($credit->id)
                && collect($expenses)->count() === 1),
        );
    }

    public function test_store_records_a_payment_and_redirects_with_success(): void
    {
        $this->actingAsAuthorizedUser();
        $expense = $this->creditExpense('500000');

        $response = $this->post(route('beban.payments.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 200000,
        ]);

        $response->assertRedirect(route('beban.payments.index'));
        $response->assertSessionHas('success');
        $this->assertSame(1, ExpensePayment::count());
        $this->assertSame('1-1000', ExpensePayment::first()->cash_account_code);
    }

    public function test_store_with_bank_selected_stores_the_bank_code(): void
    {
        $this->actingAsAuthorizedUser();
        $expense = $this->creditExpense('500000');

        $this->post(route('beban.payments.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 200000,
            'cash_account_code' => '1-1100',
        ]);

        $this->assertSame('1-1100', ExpensePayment::first()->cash_account_code);
    }

    public function test_index_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('beban.payments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Beban/Payments/Index')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_store_rejects_payment_exceeding_remaining_balance(): void
    {
        $this->actingAsAuthorizedUser();
        $expense = $this->creditExpense('500000');

        $response = $this->post(route('beban.payments.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_id' => $expense->id,
            'date' => '2026-07-10',
            'amount' => 600000,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, ExpensePayment::count());
    }

    public function test_unauthorized_user_cannot_access_payment_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('beban.payments.index'))->assertForbidden();
        $this->actingAs($user)->post(route('beban.payments.store'), [])->assertForbidden();
    }
}
