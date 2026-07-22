<?php

namespace Tests\Feature\Modal;

use App\Models\EquityTransaction;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquityTransactionControllerTest extends TestCase
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
        $permission = Permission::create(['key' => 'modal.manage', 'label' => 'Modal & Prive', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_deposit_create_page_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('modal.deposit.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Modal/CreateDeposit')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_store_deposit_records_a_modal_transaction_and_redirects(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('modal.deposit.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 1000000,
            'description' => 'Setoran awal',
        ]);

        $response->assertRedirect(route('modal.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('equity_transactions', [
            'type' => 'modal',
            'cash_account_code' => '1-1000',
        ]);
    }

    public function test_store_withdrawal_records_a_prive_transaction_and_redirects(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('modal.withdrawal.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 300000,
            'description' => 'Keperluan pribadi',
        ]);

        $response->assertRedirect(route('modal.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('equity_transactions', [
            'type' => 'prive',
            'cash_account_code' => '1-1000',
        ]);
    }

    public function test_store_deposit_rejects_a_zero_amount(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('modal.deposit.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 0,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, EquityTransaction::count());
    }

    public function test_index_filters_by_type_and_date_range(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('modal.deposit.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 1000000,
        ]);
        $this->post(route('modal.withdrawal.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 300000,
        ]);

        $response = $this->get(route('modal.index', [
            'date_from' => '2026-07-22',
            'date_to' => '2026-07-22',
            'type' => 'prive',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Modal/Index')
            ->where('transactions', fn ($transactions) => collect($transactions)->count() === 1
                && collect($transactions)->first()['type'] === 'prive'),
        );
    }

    public function test_manajer_role_does_not_get_modal_manage_permission_by_default(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $manajer = Role::where('name', 'Manajer')->firstOrFail();
        $this->assertFalse($manajer->permissions()->where('key', 'modal.manage')->exists());
    }

    public function test_unauthorized_user_cannot_access_modal_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('modal.index'))->assertForbidden();
        $this->actingAs($user)->post(route('modal.deposit.store'), [])->assertForbidden();
        $this->actingAs($user)->post(route('modal.withdrawal.store'), [])->assertForbidden();
    }
}
