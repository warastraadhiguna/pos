<?php

namespace Tests\Feature\Beban;

use App\Models\Account;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseControllerTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->outlet = Outlet::first();
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

    public function test_create_page_lists_selectable_expense_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('beban.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Beban/Create')
            ->where('expenseAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('5-3000')
                && ! collect($accounts)->pluck('code')->contains('5-1000')),
        );
    }

    public function test_store_records_a_cash_expense_and_redirects_with_success(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('beban.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-07-10',
            'amount' => 150000,
            'payment_method' => 'cash',
            'description' => 'Listrik bulan Juli',
        ]);

        $response->assertRedirect(route('beban.index'));
        $response->assertSessionHas('success');
        $this->assertSame(1, Expense::count());
        $this->assertSame(0, bccomp(Expense::first()->amount, '150000', 4));
        $this->assertSame('1-1000', Expense::first()->cash_account_code);
    }

    public function test_store_with_bank_selected_stores_the_bank_code(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('beban.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-07-10',
            'amount' => 150000,
            'payment_method' => 'cash',
            'cash_account_code' => '1-1100',
            'description' => 'Listrik dibayar via bank',
        ]);

        $this->assertSame('1-1100', Expense::first()->cash_account_code);
    }

    public function test_create_page_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('beban.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Beban/Create')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_store_requires_account_amount_and_description(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('beban.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-10',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHasErrors(['expense_account_id', 'amount', 'description']);
        $this->assertSame(0, Expense::count());
    }

    public function test_store_rejects_the_reserved_hpp_account_via_service_validation(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('beban.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-1000')->firstOrFail()->id,
            'date' => '2026-07-10',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Tidak boleh',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Expense::count());
    }

    public function test_index_filters_by_date_range_and_search(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('beban.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-07-10',
            'amount' => 100000,
            'payment_method' => 'cash',
            'description' => 'Listrik Juli',
        ]);
        $this->post(route('beban.store'), [
            'outlet_id' => $this->outlet->id,
            'expense_account_id' => Account::where('code', '5-3100')->firstOrFail()->id,
            'date' => '2026-06-01',
            'amount' => 200000,
            'payment_method' => 'cash',
            'description' => 'Sewa Juni',
        ]);

        $response = $this->get(route('beban.index', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('expenses', fn ($expenses) => collect($expenses)->pluck('description')->contains('Listrik Juli')
                && ! collect($expenses)->pluck('description')->contains('Sewa Juni')),
        );
    }

    public function test_unauthorized_user_cannot_access_beban_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('beban.index'))->assertForbidden();
        $this->actingAs($user)->get(route('beban.create'))->assertForbidden();
        $this->actingAs($user)->post(route('beban.store'), [])->assertForbidden();
    }
}
