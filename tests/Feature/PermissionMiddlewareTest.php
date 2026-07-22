<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function roleWith(array $permissionKeys): Role
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);

        $permissions = collect($permissionKeys)->map(
            fn (string $key) => Permission::create(['key' => $key, 'label' => $key, 'group' => 'Test']),
        );

        $role->permissions()->attach($permissions->pluck('id'));

        return $role;
    }

    public function test_user_without_required_permission_gets_403(): void
    {
        $role = $this->roleWith(['kasir.access']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $response = $this->actingAs($user)->get('/master/uoms');

        $response->assertForbidden();
    }

    public function test_user_with_required_permission_can_access_route(): void
    {
        $role = $this->roleWith(['master-data.manage']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $response = $this->actingAs($user)->get('/master/uoms');

        $response->assertOk();
    }

    public function test_user_without_any_role_gets_403_on_gated_routes(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $response = $this->actingAs($user)->get('/kasir');

        $response->assertForbidden();
    }

    public function test_dashboard_is_accessible_regardless_of_permissions(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
    }

    public function test_kasir_role_cannot_access_master_data(): void
    {
        // /kasir reads the company_settings singleton (PPN switch) to pass
        // to its client-side estimate — needs a row to exist, same as it
        // always would in a real, seeded database. show_stock_on_button
        // defaults to true, so /kasir also resolves the single
        // outlet/warehouse (to compute producible qty) — same as it
        // always would in a real, seeded database (FoundationSeeder always
        // creates exactly one of each).
        CompanySetting::create(['ppn_active' => true]);
        $outlet = Outlet::create(['name' => 'Outlet Pusat']);
        Warehouse::create(['outlet_id' => $outlet->id, 'name' => 'Gudang Utama']);

        $role = $this->roleWith(['kasir.access', 'penjualan.view']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get('/kasir')->assertOk();
        $this->actingAs($user)->get('/master/products')->assertForbidden();
        $this->actingAs($user)->get('/pengguna')->assertForbidden();
    }
}
