<?php

namespace Tests\Feature\Master;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryControllerTest extends TestCase
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

    private function actingAsAuthorizedUser(): User
    {
        $user = User::factory()->create(['role_id' => $this->roleWith(['master-data.manage'])->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_index_lists_categories_ordered_by_name(): void
    {
        $this->actingAsAuthorizedUser();
        ProductCategory::create(['name' => 'Zebra']);
        ProductCategory::create(['name' => 'Apel']);

        $response = $this->get(route('master.product-categories.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Master/ProductCategories/Index')
            ->where('productCategories.0.name', 'Apel')
            ->where('productCategories.1.name', 'Zebra'),
        );
    }

    public function test_store_creates_a_category(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.product-categories.store'), ['name' => 'Minuman']);

        $response->assertRedirect(route('master.product-categories.index'));
        $this->assertSame(1, ProductCategory::count());
        $this->assertSame('Minuman', ProductCategory::first()->name);
    }

    public function test_store_requires_a_name(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.product-categories.store'), ['name' => '']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, ProductCategory::count());
    }

    public function test_update_renames_a_category(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ProductCategory::create(['name' => 'Lama']);

        $response = $this->put(route('master.product-categories.update', $category), ['name' => 'Baru']);

        $response->assertRedirect(route('master.product-categories.index'));
        $this->assertSame('Baru', $category->fresh()->name);
    }

    public function test_destroy_deletes_a_category_that_is_not_used_by_any_product(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ProductCategory::create(['name' => 'Tidak Dipakai']);

        $response = $this->delete(route('master.product-categories.destroy', $category));

        $response->assertRedirect(route('master.product-categories.index'));
        $this->assertSame(0, ProductCategory::count());
    }

    public function test_destroy_is_blocked_when_a_product_still_uses_the_category(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ProductCategory::create(['name' => 'Minuman']);
        Product::create(['name' => 'Kopi', 'sell_price' => 8000, 'product_category_id' => $category->id]);
        Product::create(['name' => 'Teh', 'sell_price' => 5000, 'product_category_id' => $category->id]);

        $response = $this->delete(route('master.product-categories.destroy', $category));

        $response->assertRedirect(route('master.product-categories.index'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('2 produk', session('error'));
        // Kategori TETAP ada, dan produk yang memakainya TIDAK kehilangan
        // kategorinya (buktikan tidak diam-diam di-null-kan).
        $this->assertSame(1, ProductCategory::count());
        $this->assertSame(2, Product::whereNotNull('product_category_id')->count());
    }

    public function test_unauthorized_user_cannot_access_category_pages(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $this->actingAs($kasir)->get(route('master.product-categories.index'))->assertForbidden();
        $this->actingAs($kasir)->post(route('master.product-categories.store'), ['name' => 'X'])->assertForbidden();
    }
}
