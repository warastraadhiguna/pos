<?php

namespace Tests\Feature\Master;

use App\Models\Account;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemControllerTest extends TestCase
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
        $role->permissions()->attach(
            Permission::create(['key' => 'master-data.manage', 'label' => 'master-data.manage', 'group' => 'Test'])->id,
        );
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    private function baseItemPayload(array $overrides = []): array
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $persediaan = Account::where('code', '1-1200')->firstOrFail();

        return array_merge([
            'sku' => 'ITEM-UJI-'.uniqid(),
            'name' => 'Item Uji',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 1000,
            'inventory_account_id' => $persediaan->id,
            'is_active' => true,
        ], $overrides);
    }

    public function test_item_category_can_be_assigned_when_creating(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ItemCategory::create(['name' => 'Bahan Baku']);

        $this->post(route('master.items.store'), $this->baseItemPayload([
            'item_category_id' => $category->id,
        ]))->assertRedirect(route('master.items.index'));

        $item = Item::where('name', 'Item Uji')->firstOrFail();
        $this->assertSame($category->id, $item->item_category_id);
    }

    public function test_item_category_is_optional_and_defaults_to_null(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.items.store'), $this->baseItemPayload())
            ->assertRedirect(route('master.items.index'));

        $item = Item::where('name', 'Item Uji')->firstOrFail();
        $this->assertNull($item->item_category_id);
    }

    public function test_item_category_can_be_changed_on_update(): void
    {
        $this->actingAsAuthorizedUser();
        $bahanBaku = ItemCategory::create(['name' => 'Bahan Baku']);
        $kemasan = ItemCategory::create(['name' => 'Kemasan']);

        $this->post(route('master.items.store'), $this->baseItemPayload([
            'item_category_id' => $bahanBaku->id,
        ]));
        $item = Item::where('name', 'Item Uji')->firstOrFail();

        $this->put(route('master.items.update', $item), $this->baseItemPayload([
            'sku' => $item->sku,
            'item_category_id' => $kemasan->id,
        ]))->assertRedirect(route('master.items.index'));

        $this->assertSame($kemasan->id, $item->fresh()->item_category_id);
    }

    public function test_a_nonexistent_item_category_id_is_rejected(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.items.store'), $this->baseItemPayload([
            'item_category_id' => 99999,
        ]));

        $response->assertSessionHasErrors(['item_category_id']);
        $this->assertSame(0, Item::count());
    }
}
