<?php

namespace Tests\Feature\Coa;

use App\Models\Account;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsControllerTest extends TestCase
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
        $permission = Permission::create(['key' => 'coa.manage', 'label' => 'CoA', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_index_lists_all_accounts_with_balance_and_protected_flag(): void
    {
        $this->actingAsAuthorizedUser();

        (new PostingService())->post(
            lines: [
                ['account' => '1-1000', 'debit' => 250000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 250000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Penjualan',
        );

        $response = $this->get(route('coa.index', ['as_of' => '2026-07-01']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Coa/Index')
            ->where('asOf', '2026-07-01')
            ->where('accounts', function ($accounts) {
                $byCode = collect($accounts)->keyBy('code');

                return $byCode['1-1000']['balance'] === '250000.0000'
                    && $byCode['1-1000']['is_protected'] === true
                    && $byCode->has('1-2100') === false; // belum ada akun ini
            }),
        );
    }

    public function test_store_adds_a_new_account_outside_protected_ranges(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('coa.store'), [
            'code' => '1-2100',
            'name' => 'Deposit Sewa',
            'type' => 'asset',
        ]);

        $response->assertRedirect(route('coa.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('accounts', [
            'code' => '1-2100',
            'name' => 'Deposit Sewa',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);
    }

    public function test_store_rejects_a_code_in_the_kas_bank_range(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('coa.store'), [
            'code' => '1-1500',
            'name' => 'X',
            'type' => 'asset',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('accounts', ['code' => '1-1500']);
    }

    public function test_store_rejects_a_code_in_the_cogs_range(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('coa.store'), [
            'code' => '5-1500',
            'name' => 'X',
            'type' => 'expense',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('accounts', ['code' => '5-1500']);
    }

    public function test_store_rejects_a_duplicate_code(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('coa.store'), [
            'code' => '1-1000',
            'name' => 'Duplikat',
            'type' => 'asset',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_store_rejects_a_mismatched_leading_digit(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('coa.store'), [
            'code' => '2-9999',
            'name' => 'X',
            'type' => 'asset',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('accounts', ['code' => '2-9999']);
    }

    public function test_toggle_active_flips_a_normal_accounts_status(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('coa.store'), ['code' => '1-2100', 'name' => 'Deposit Sewa', 'type' => 'asset']);
        $account = Account::where('code', '1-2100')->firstOrFail();

        $this->put(route('coa.toggle-active', $account->id));

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_toggle_active_rejects_deactivating_a_protected_system_account(): void
    {
        $this->actingAsAuthorizedUser();
        $kas = Account::where('code', '1-1000')->firstOrFail();

        $response = $this->put(route('coa.toggle-active', $kas->id));

        $response->assertSessionHas('error');
        $this->assertTrue($kas->fresh()->is_active);
    }

    public function test_manajer_role_does_not_get_coa_manage_permission_by_default(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $manajer = Role::where('name', 'Manajer')->firstOrFail();
        $this->assertFalse($manajer->permissions()->where('key', 'coa.manage')->exists());
    }

    public function test_unauthorized_user_cannot_access_coa_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('coa.index'))->assertForbidden();
        $this->actingAs($user)->post(route('coa.store'), [])->assertForbidden();
    }
}
