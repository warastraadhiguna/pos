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

class ItemSearchTest extends TestCase
{
    use RefreshDatabase;

    private Uom $pcs;

    private Account $account;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->account = Account::where('code', '1-1200')->firstOrFail();
    }

    private function userWithPermission(string $key): User
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach($permission->id);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeItem(string $sku, string $name, bool $active = true): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $name,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->account->id,
            'is_active' => $active,
        ]);
    }

    public function test_user_without_master_data_permission_gets_403(): void
    {
        $user = $this->userWithPermission('kasir.access');

        $this->actingAs($user)->getJson('/master/items/search?q=cola')->assertForbidden();
    }

    public function test_matches_by_sku_substring(): void
    {
        $user = $this->userWithPermission('master-data.manage');
        $this->makeItem('COKE-350ML', 'Coca-Cola 350ml');
        $this->makeItem('SPRITE-330ML', 'Sprite Kaleng 330ml');

        $response = $this->actingAs($user)->getJson('/master/items/search?q=COKE');

        $response->assertOk();
        $skus = collect($response->json())->pluck('sku');
        $this->assertTrue($skus->contains('COKE-350ML'));
        $this->assertFalse($skus->contains('SPRITE-330ML'));
    }

    public function test_matches_by_name_substring(): void
    {
        $user = $this->userWithPermission('master-data.manage');
        $this->makeItem('COKE-350ML', 'Coca-Cola 350ml');
        $this->makeItem('SPRITE-330ML', 'Sprite Kaleng 330ml');

        $response = $this->actingAs($user)->getJson('/master/items/search?q=Sprite');

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Sprite Kaleng 330ml'));
        $this->assertFalse($names->contains('Coca-Cola 350ml'));
    }

    public function test_inactive_items_are_excluded(): void
    {
        $user = $this->userWithPermission('master-data.manage');
        $this->makeItem('OLD-ITEM', 'Barang Lama', active: false);

        $response = $this->actingAs($user)->getJson('/master/items/search?q=Barang');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_empty_query_returns_results_capped_at_20(): void
    {
        $user = $this->userWithPermission('master-data.manage');

        foreach (range(1, 25) as $i) {
            $this->makeItem('BULK-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'Bulk Item '.$i);
        }

        $response = $this->actingAs($user)->getJson('/master/items/search');

        $response->assertOk();
        $this->assertCount(20, $response->json());
    }

    public function test_result_includes_purchase_uom_id_for_po_form_auto_fill(): void
    {
        $user = $this->userWithPermission('master-data.manage');
        $item = $this->makeItem('COKE-350ML', 'Coca-Cola 350ml');

        $response = $this->actingAs($user)->getJson('/master/items/search?q=COKE');

        $response->assertOk();
        $this->assertSame($item->purchase_uom_id, $response->json('0.purchase_uom_id'));
    }

    public function test_response_does_not_scale_with_total_item_count(): void
    {
        $user = $this->userWithPermission('master-data.manage');

        foreach (range(1, 30) as $i) {
            $this->makeItem('MANY-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'Many Item '.$i);
        }

        $response = $this->actingAs($user)->getJson('/master/items/search?q=MANY-005');

        $response->assertOk();
        $skus = collect($response->json())->pluck('sku');
        $this->assertCount(1, $skus);
        $this->assertSame(['MANY-005'], $skus->all());
    }
}
