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

class ItemCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fondasi UOM + akun dibutuhkan cuma untuk fixture Item di test
        // proteksi-hapus di bawah — bukan oleh kategori itu sendiri.
        $this->seed(FoundationSeeder::class);
    }

    private function roleWith(array $permissionKeys): Role
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);

        $permissions = collect($permissionKeys)->map(
            fn (string $key) => Permission::create(['key' => $key, 'label' => $key, 'group' => 'Test']),
        );

        $role->permissions()->attach($permissions->pluck('id'));

        return $role;
    }

    private function actingAsAuthorizedUser(): User
    {
        $user = User::factory()->create(['role_id' => $this->roleWith(['master-data.manage'])->id]);
        $this->actingAs($user);

        return $user;
    }

    private function makeItem(string $sku, ?ItemCategory $category = null): Item
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();

        return Item::create([
            'sku' => $sku,
            'name' => $sku,
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $account->id,
            'item_category_id' => $category?->id,
        ]);
    }

    public function test_index_lists_categories_ordered_by_name(): void
    {
        $this->actingAsAuthorizedUser();
        ItemCategory::create(['name' => 'Zebra']);
        ItemCategory::create(['name' => 'Apel']);

        $response = $this->get(route('master.item-categories.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Master/ItemCategories/Index')
            ->where('itemCategories.0.name', 'Apel')
            ->where('itemCategories.1.name', 'Zebra'),
        );
    }

    public function test_store_creates_a_category(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.item-categories.store'), ['name' => 'Bahan Baku']);

        $response->assertRedirect(route('master.item-categories.index'));
        $this->assertSame(1, ItemCategory::count());
        $this->assertSame('Bahan Baku', ItemCategory::first()->name);
    }

    public function test_store_requires_a_name(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.item-categories.store'), ['name' => '']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, ItemCategory::count());
    }

    public function test_update_renames_a_category(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ItemCategory::create(['name' => 'Lama']);

        $response = $this->put(route('master.item-categories.update', $category), ['name' => 'Baru']);

        $response->assertRedirect(route('master.item-categories.index'));
        $this->assertSame('Baru', $category->fresh()->name);
    }

    public function test_destroy_deletes_a_category_that_is_not_used_by_any_item(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ItemCategory::create(['name' => 'Tidak Dipakai']);

        $response = $this->delete(route('master.item-categories.destroy', $category));

        $response->assertRedirect(route('master.item-categories.index'));
        $this->assertSame(0, ItemCategory::count());
    }

    public function test_destroy_is_blocked_when_an_item_still_uses_the_category(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ItemCategory::create(['name' => 'Bahan Baku']);
        $this->makeItem('ITEM-1', $category);

        $response = $this->delete(route('master.item-categories.destroy', $category));

        $response->assertRedirect(route('master.item-categories.index'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('1 item', session('error'));
        $this->assertSame(1, ItemCategory::count());
        $this->assertSame(1, Item::whereNotNull('item_category_id')->count());
    }

    public function test_unauthorized_user_cannot_access_category_pages(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $this->actingAs($kasir)->get(route('master.item-categories.index'))->assertForbidden();
        $this->actingAs($kasir)->post(route('master.item-categories.store'), ['name' => 'X'])->assertForbidden();
    }
}
