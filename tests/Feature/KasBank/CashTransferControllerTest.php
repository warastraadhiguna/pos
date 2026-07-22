<?php

namespace Tests\Feature\KasBank;

use App\Models\CashTransfer;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashTransferControllerTest extends TestCase
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
        $permission = Permission::create(['key' => 'kas-bank.manage', 'label' => 'Kas & Bank', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_create_page_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('kas-bank.transfers.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('KasBank/Transfers/Create')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_store_records_a_transfer_and_redirects(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('kas-bank.transfers.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1100',
            'amount' => 300000,
            'memo' => 'Setor ke bank',
        ]);

        $response->assertRedirect(route('kas-bank.transfers.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('cash_transfers', [
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1100',
            'memo' => 'Setor ke bank',
        ]);
    }

    public function test_store_rejects_the_same_account_on_both_sides(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('kas-bank.transfers.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1000',
            'amount' => 100000,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, CashTransfer::count());
    }

    public function test_index_lists_transfers_within_date_range(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('kas-bank.transfers.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1100',
            'amount' => 150000,
        ]);

        $response = $this->get(route('kas-bank.transfers.index', [
            'date_from' => '2026-07-22',
            'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('KasBank/Transfers/Index')
            ->where('transfers', fn ($transfers) => collect($transfers)->count() === 1),
        );
    }

    public function test_unauthorized_user_cannot_access_transfer_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('kas-bank.transfers.index'))->assertForbidden();
        $this->actingAs($user)->post(route('kas-bank.transfers.store'), [])->assertForbidden();
    }
}
