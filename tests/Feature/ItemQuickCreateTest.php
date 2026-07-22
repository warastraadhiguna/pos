<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

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
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $response = $this->actingAs($user)->postJson('/master/items/quick-create', [
            'sku' => 'COKE-350ML',
            'name' => 'Coca-Cola 350ml',
            'base_uom_id' => $pcs->id,
            'standard_cost' => 8000,
        ]);

        $response->assertForbidden();
    }

    public function test_creates_a_stocked_item_with_resolved_inventory_account(): void
    {
        $user = $this->userWithPermission('master-data.manage');
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $persediaanAccount = Account::where('code', '1-1200')->firstOrFail();

        $response = $this->actingAs($user)->postJson('/master/items/quick-create', [
            'sku' => 'COKE-350ML',
            'name' => 'Coca-Cola 350ml',
            'base_uom_id' => $pcs->id,
            'standard_cost' => 8000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('sku', 'COKE-350ML');
        $response->assertJsonPath('name', 'Coca-Cola 350ml');

        $item = Item::where('sku', 'COKE-350ML')->firstOrFail();

        $this->assertSame('stocked', $item->costing_type);
        $this->assertSame($pcs->id, $item->base_uom_id);
        $this->assertSame($pcs->id, $item->purchase_uom_id);
        $this->assertSame($persediaanAccount->id, $item->inventory_account_id);
        $this->assertSame(0, bccomp($item->standard_cost, '8000', 4));
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $user = $this->userWithPermission('master-data.manage');
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();

        Item::create([
            'sku' => 'COKE-350ML',
            'name' => 'Existing',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $account->id,
        ]);

        $response = $this->actingAs($user)->postJson('/master/items/quick-create', [
            'sku' => 'COKE-350ML',
            'name' => 'Coca-Cola 350ml',
            'base_uom_id' => $pcs->id,
            'standard_cost' => 8000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['sku']]);
    }
}
