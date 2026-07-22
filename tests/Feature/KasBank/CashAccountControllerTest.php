<?php

namespace Tests\Feature\KasBank;

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashAccountControllerTest extends TestCase
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
        $permission = Permission::create(['key' => 'kas-bank.manage', 'label' => 'Kas & Bank', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_index_lists_kas_and_bank_but_not_the_group_header(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('kas-bank.accounts.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('KasBank/Accounts/Index')
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')
                && ! collect($accounts)->pluck('code')->contains('1-1')),
        );
    }

    public function test_store_adds_a_new_bank_account_with_a_valid_1_11xx_code(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('kas-bank.accounts.store'), [
            'code' => '1-1101',
            'name' => 'Bank Mandiri',
        ]);

        $response->assertRedirect(route('kas-bank.accounts.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('accounts', ['code' => '1-1101', 'name' => 'Bank Mandiri', 'type' => 'asset']);
    }

    public function test_store_rejects_a_code_outside_the_1_11xx_format(): void
    {
        $this->actingAsAuthorizedUser();

        // Kode belum dipakai (lolos validasi unique bawaan Laravel) tapi
        // format salah -- supaya yang benar-benar diuji adalah penolakan
        // CashAccountService::createBankAccount(), bukan aturan unique.
        $response = $this->post(route('kas-bank.accounts.store'), [
            'code' => '9-9999',
            'name' => 'Salah Format',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('accounts', ['code' => '9-9999', 'name' => 'Salah Format']);
    }

    public function test_store_rejects_a_duplicate_code(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('kas-bank.accounts.store'), [
            'code' => '1-1100',
            'name' => 'Duplikat',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_toggle_active_flips_the_banks_status(): void
    {
        $this->actingAsAuthorizedUser();
        $account = Account::where('code', '1-1100')->firstOrFail();
        $this->assertTrue($account->is_active);

        $this->put(route('kas-bank.accounts.toggle-active', $account->id));

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_unauthorized_user_cannot_access_account_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('kas-bank.accounts.index'))->assertForbidden();
        $this->actingAs($user)->post(route('kas-bank.accounts.store'), [])->assertForbidden();
    }
}
