<?php

namespace Tests\Feature\Aset;

use App\Models\FixedAsset;
use App\Models\FixedAssetPayment;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CashAccountService;
use App\Services\FixedAssetService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private FixedAssetService $fixedAssets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        $this->outlet = Outlet::first();
        $this->fixedAssets = new FixedAssetService(new PostingService(), new CashAccountService());
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

    private function creditAsset(): FixedAsset
    {
        return $this->fixedAssets->recordPurchase([
            'outlet_id' => $this->outlet->id,
            'name' => 'Mesin Kredit',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 5000000,
            'residual_value' => 0,
            'useful_life_months' => 24,
            'payment_method' => 'credit',
        ]);
    }

    public function test_index_lists_only_unpaid_credit_assets(): void
    {
        $this->actingAsAuthorizedUser();
        $credit = $this->creditAsset();
        $this->fixedAssets->recordPurchase([
            'outlet_id' => $this->outlet->id,
            'name' => 'Mesin Tunai',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 1000000,
            'residual_value' => 0,
            'useful_life_months' => 12,
            'payment_method' => 'cash',
        ]);

        $response = $this->get(route('aset.payments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Aset/Payments/Index')
            ->where('unpaidAssets', fn ($assets) => collect($assets)->pluck('fixed_asset_id')->contains($credit->id)
                && collect($assets)->count() === 1),
        );
    }

    public function test_store_records_a_payment_and_redirects(): void
    {
        $this->actingAsAuthorizedUser();
        $asset = $this->creditAsset();

        $response = $this->post(route('aset.payments.store'), [
            'outlet_id' => $this->outlet->id,
            'fixed_asset_id' => $asset->id,
            'date' => '2026-02-10',
            'amount' => 2000000,
        ]);

        $response->assertRedirect(route('aset.payments.index'));
        $response->assertSessionHas('success');
        $this->assertSame(1, FixedAssetPayment::count());
        $this->assertSame('1-1000', FixedAssetPayment::first()->cash_account_code);
    }

    public function test_store_rejects_payment_exceeding_remaining_balance(): void
    {
        $this->actingAsAuthorizedUser();
        $asset = $this->creditAsset();

        $response = $this->post(route('aset.payments.store'), [
            'outlet_id' => $this->outlet->id,
            'fixed_asset_id' => $asset->id,
            'date' => '2026-02-10',
            'amount' => 6000000,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, FixedAssetPayment::count());
    }

    public function test_unauthorized_user_cannot_access_payment_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('aset.payments.index'))->assertForbidden();
        $this->actingAs($user)->post(route('aset.payments.store'), [])->assertForbidden();
    }
}
