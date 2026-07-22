<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSearchTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(string $key): User
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach($permission->id);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_user_without_master_data_permission_gets_403(): void
    {
        $user = $this->userWithPermission('kasir.access');

        $this->actingAs($user)->getJson('/master/suppliers/search?q=joko')->assertForbidden();
    }

    public function test_matches_by_name_substring(): void
    {
        $user = $this->userWithPermission('master-data.manage');
        Supplier::create(['name' => 'Toko Joko Jaya']);
        Supplier::create(['name' => 'CV Sumber Makmur']);

        $response = $this->actingAs($user)->getJson('/master/suppliers/search?q=joko');

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Toko Joko Jaya'));
        $this->assertFalse($names->contains('CV Sumber Makmur'));
    }

    public function test_empty_query_returns_results_capped_at_20(): void
    {
        $user = $this->userWithPermission('master-data.manage');

        foreach (range(1, 25) as $i) {
            Supplier::create(['name' => 'Supplier '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }

        $response = $this->actingAs($user)->getJson('/master/suppliers/search');

        $response->assertOk();
        $this->assertCount(20, $response->json());
    }

    public function test_response_does_not_scale_with_total_supplier_count(): void
    {
        $user = $this->userWithPermission('master-data.manage');

        foreach (range(1, 30) as $i) {
            Supplier::create(['name' => 'Many Supplier '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }

        $response = $this->actingAs($user)->getJson('/master/suppliers/search?q=Many Supplier 005');

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertCount(1, $names);
        $this->assertSame(['Many Supplier 005'], $names->all());
    }
}
