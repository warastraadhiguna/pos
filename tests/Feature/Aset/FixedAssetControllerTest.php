<?php

namespace Tests\Feature\Aset;

use App\Models\FixedAsset;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetControllerTest extends TestCase
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
        $permission = Permission::create(['key' => 'aset.manage', 'label' => 'Aset Tetap', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_create_page_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('aset.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Aset/Create')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_store_records_a_cash_asset_and_redirects(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('aset.store'), [
            'outlet_id' => $this->outlet->id,
            'name' => 'Kulkas Sanken',
            'category' => 'Peralatan',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 12000000,
            'residual_value' => 0,
            'useful_life_years' => 4,
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect(route('aset.index'));
        $response->assertSessionHas('success');
        $this->assertSame(1, FixedAsset::count());
        $this->assertSame(48, FixedAsset::first()->useful_life_months);
        $this->assertSame('1-1000', FixedAsset::first()->cash_account_code);
    }

    public function test_store_with_bank_selected_stores_the_bank_code(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('aset.store'), [
            'outlet_id' => $this->outlet->id,
            'name' => 'Kulkas Sanken',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 12000000,
            'useful_life_years' => 4,
            'payment_method' => 'cash',
            'cash_account_code' => '1-1100',
        ]);

        $this->assertSame('1-1100', FixedAsset::first()->cash_account_code);
    }

    public function test_store_records_a_credit_asset(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('aset.store'), [
            'outlet_id' => $this->outlet->id,
            'name' => 'Mesin Kredit',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 12000000,
            'useful_life_years' => 4,
            'payment_method' => 'credit',
        ]);

        $this->assertSame('credit', FixedAsset::first()->payment_method);
    }

    public function test_store_rejects_missing_required_fields(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('aset.store'), [
            'outlet_id' => $this->outlet->id,
        ]);

        $response->assertSessionHasErrors(['name', 'purchase_date', 'acquisition_cost', 'useful_life_years', 'payment_method']);
        $this->assertSame(0, FixedAsset::count());
    }

    public function test_index_shows_book_value(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('aset.store'), [
            'outlet_id' => $this->outlet->id,
            'name' => 'Kulkas Sanken',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 12000000,
            'useful_life_years' => 4,
            'payment_method' => 'cash',
        ]);

        $response = $this->get(route('aset.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Aset/Index')
            ->where('assets', fn ($assets) => collect($assets)->first()['book_value'] === '12000000.0000'),
        );
    }

    public function test_unauthorized_user_cannot_access_asset_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('aset.index'))->assertForbidden();
        $this->actingAs($user)->post(route('aset.store'), [])->assertForbidden();
    }
}
