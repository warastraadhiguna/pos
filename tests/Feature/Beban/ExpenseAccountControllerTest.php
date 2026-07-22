<?php

namespace Tests\Feature\Beban;

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
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

    public function test_index_lists_all_expense_type_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('beban.accounts.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Beban/Accounts/Index')
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('5-3000')
                && collect($accounts)->pluck('code')->contains('5-1000')), // termasuk akun sistem, hanya read-only di UI
        );
    }

    public function test_store_adds_a_new_expense_account_with_valid_5_3_code(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('beban.accounts.store'), [
            'code' => '5-3400',
            'name' => 'Beban Parkir',
        ]);

        $response->assertRedirect(route('beban.accounts.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('accounts', ['code' => '5-3400', 'name' => 'Beban Parkir', 'type' => 'expense']);
    }

    public function test_store_rejects_a_code_outside_the_5_3_range(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('beban.accounts.store'), [
            'code' => '5-1500',
            'name' => 'Salah Rentang',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('accounts', ['code' => '5-1500']);
    }

    public function test_store_rejects_a_duplicate_code(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('beban.accounts.store'), [
            'code' => '5-3000',
            'name' => 'Duplikat',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_toggle_active_flips_the_accounts_status(): void
    {
        $this->actingAsAuthorizedUser();
        $account = Account::where('code', '5-3000')->firstOrFail();
        $this->assertTrue($account->is_active);

        $this->put(route('beban.accounts.toggle-active', $account->id));

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_unauthorized_user_cannot_access_account_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('beban.accounts.index'))->assertForbidden();
        $this->actingAs($user)->post(route('beban.accounts.store'), [])->assertForbidden();
    }
}
