<?php

namespace Tests\Feature\Aset;

use App\Models\DepreciationEntry;
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

class DepreciationControllerTest extends TestCase
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

    private function makeAsset(): void
    {
        (new FixedAssetService(new PostingService(), new CashAccountService()))->recordPurchase([
            'outlet_id' => $this->outlet->id,
            'name' => 'Kulkas Sanken',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 12000000,
            'residual_value' => 0,
            'useful_life_months' => 48,
            'payment_method' => 'cash',
        ]);
    }

    public function test_index_shows_a_preview_for_the_requested_period(): void
    {
        $this->actingAsAuthorizedUser();
        $this->makeAsset();

        $response = $this->get(route('aset.depreciation.index', ['period' => '2026-02']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Aset/Depreciation/Index')
            ->where('period', '2026-02')
            ->where('preview', fn ($preview) => collect($preview)->count() === 1
                && collect($preview)->first()['amount'] === '250000.0000'),
        );
    }

    public function test_process_creates_entries_and_redirects(): void
    {
        $this->actingAsAuthorizedUser();
        $this->makeAsset();

        $response = $this->post(route('aset.depreciation.process'), [
            'period' => '2026-02',
            'date' => '2026-02-28',
        ]);

        $response->assertRedirect(route('aset.depreciation.index', ['period' => '2026-02']));
        $response->assertSessionHas('success');
        $this->assertSame(1, DepreciationEntry::count());
    }

    public function test_processing_the_same_period_twice_is_safe_and_creates_no_duplicate(): void
    {
        $this->actingAsAuthorizedUser();
        $this->makeAsset();

        $this->post(route('aset.depreciation.process'), ['period' => '2026-02', 'date' => '2026-02-28']);
        $this->post(route('aset.depreciation.process'), ['period' => '2026-02', 'date' => '2026-02-28']);

        $this->assertSame(1, DepreciationEntry::count());
    }

    public function test_process_rejects_an_invalid_period_format(): void
    {
        $this->actingAsAuthorizedUser();
        $this->makeAsset();

        $response = $this->post(route('aset.depreciation.process'), [
            'period' => '2026/02',
            'date' => '2026-02-28',
        ]);

        $response->assertSessionHasErrors('period');
        $this->assertSame(0, DepreciationEntry::count());
    }

    public function test_unauthorized_user_cannot_access_depreciation_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('aset.depreciation.index'))->assertForbidden();
        $this->actingAs($user)->post(route('aset.depreciation.process'), [])->assertForbidden();
    }
}
