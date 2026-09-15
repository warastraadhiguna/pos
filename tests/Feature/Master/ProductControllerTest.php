<?php

namespace Tests\Feature\Master;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductControllerTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // Disk publik nyata (bukan disk default `local`) khusus untuk file
        // yang benar-benar ditulis test ini -- Storage::fake() membuat disk
        // in-memory terpisah per test, jadi tidak pernah menyentuh
        // storage/app/public sungguhan milik developer.
        Storage::fake('public');
    }

    private function baseProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Produk Uji',
            'barcode' => '',
            'sell_price' => 10000,
            'tax_rate_id' => '',
            'is_active' => true,
            'components' => [],
        ], $overrides);
    }

    public function test_uploading_an_image_on_create_generates_thumbnail_and_web_versions(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            'image' => UploadedFile::fake()->image('produk.jpg', 800, 600),
        ]));

        $response->assertRedirect(route('master.products.index'));

        $product = Product::where('name', 'Produk Uji')->firstOrFail();
        $this->assertNotNull($product->image_path);
        $this->assertNotNull($product->image_path_web);
        $this->assertNotNull($product->image_hash);
        $this->assertStringContainsString($product->image_hash, $product->image_path);
        $this->assertStringContainsString($product->image_hash, $product->image_path_web);

        Storage::disk('public')->assertExists($product->image_path);
        Storage::disk('public')->assertExists($product->image_path_web);

        // Thumbnail persegi ~200px, versi web maks ~600px sisi terpanjang.
        $thumbInfo = getimagesize(Storage::disk('public')->path($product->image_path));
        $this->assertSame(200, $thumbInfo[0]);
        $this->assertSame(200, $thumbInfo[1]);

        $webInfo = getimagesize(Storage::disk('public')->path($product->image_path_web));
        $this->assertLessThanOrEqual(600, $webInfo[0]);
        $this->assertLessThanOrEqual(600, $webInfo[1]);

        $this->assertNotNull($product->image_url);
        $this->assertStringContainsString($product->image_path, $product->image_url);
    }

    public function test_replacing_an_image_deletes_the_old_files_and_changes_the_hash(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'image' => UploadedFile::fake()->image('pertama.jpg', 400, 400),
        ]));
        $product = Product::where('name', 'Produk Uji')->firstOrFail();
        $oldThumbPath = $product->image_path;
        $oldWebPath = $product->image_path_web;
        $oldHash = $product->image_hash;

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'image' => UploadedFile::fake()->image('kedua.jpg', 400, 400),
        ]))->assertRedirect(route('master.products.index'));

        $product->refresh();

        // Hash BERUBAH -- inilah mekanisme invalidasi cache: URL baru,
        // bukan menimpa file lama di path yang sama.
        $this->assertNotSame($oldHash, $product->image_hash);
        $this->assertNotSame($oldThumbPath, $product->image_path);

        Storage::disk('public')->assertMissing($oldThumbPath);
        Storage::disk('public')->assertMissing($oldWebPath);
        Storage::disk('public')->assertExists($product->image_path);
        Storage::disk('public')->assertExists($product->image_path_web);
    }

    public function test_removing_an_image_deletes_the_files_and_clears_the_columns(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'image' => UploadedFile::fake()->image('produk.jpg', 400, 400),
        ]));
        $product = Product::where('name', 'Produk Uji')->firstOrFail();
        $thumbPath = $product->image_path;
        $webPath = $product->image_path_web;

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'remove_image' => true,
        ]))->assertRedirect(route('master.products.index'));

        $product->refresh();
        $this->assertNull($product->image_path);
        $this->assertNull($product->image_path_web);
        $this->assertNull($product->image_hash);
        $this->assertNull($product->image_url);

        Storage::disk('public')->assertMissing($thumbPath);
        Storage::disk('public')->assertMissing($webPath);
    }

    public function test_deleting_a_product_removes_its_image_files(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'image' => UploadedFile::fake()->image('produk.jpg', 400, 400),
        ]));
        $product = Product::where('name', 'Produk Uji')->firstOrFail();
        $thumbPath = $product->image_path;
        $webPath = $product->image_path_web;

        $this->delete(route('master.products.destroy', $product));

        Storage::disk('public')->assertMissing($thumbPath);
        Storage::disk('public')->assertMissing($webPath);
    }

    public function test_product_without_any_image_upload_is_created_normally(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.products.store'), $this->baseProductPayload())
            ->assertRedirect(route('master.products.index'));

        $product = Product::where('name', 'Produk Uji')->firstOrFail();
        $this->assertNull($product->image_path);
        $this->assertNull($product->image_url);
    }

    public function test_oversized_image_upload_is_rejected_and_product_is_not_created(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            // fake()->create() bikin file "gambar" 6MB tanpa isi piksel
            // sungguhan (cepat) -- cukup untuk menguji validasi ukuran
            // SEBELUM sampai ke Intervention Image sama sekali.
            'image' => UploadedFile::fake()->create('besar.jpg', 6000, 'image/jpeg'),
        ]));

        $response->assertSessionHasErrors(['image']);
        $this->assertSame(0, Product::count());
    }

    public function test_non_image_file_upload_is_rejected(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            'image' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
        ]));

        $response->assertSessionHasErrors(['image']);
        $this->assertSame(0, Product::count());
    }

    public function test_product_category_can_be_assigned_when_creating(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ProductCategory::create(['name' => 'Minuman']);

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'product_category_id' => $category->id,
        ]))->assertRedirect(route('master.products.index'));

        $product = Product::where('name', 'Produk Uji')->firstOrFail();
        $this->assertSame($category->id, $product->product_category_id);
    }

    public function test_product_category_is_optional_and_defaults_to_null(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.products.store'), $this->baseProductPayload())
            ->assertRedirect(route('master.products.index'));

        $product = Product::where('name', 'Produk Uji')->firstOrFail();
        $this->assertNull($product->product_category_id);
    }

    public function test_product_category_can_be_changed_on_update(): void
    {
        $this->actingAsAuthorizedUser();
        $minuman = ProductCategory::create(['name' => 'Minuman']);
        $makanan = ProductCategory::create(['name' => 'Makanan']);

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'product_category_id' => $minuman->id,
        ]));
        $product = Product::where('name', 'Produk Uji')->firstOrFail();

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'product_category_id' => $makanan->id,
        ]))->assertRedirect(route('master.products.index'));

        $this->assertSame($makanan->id, $product->fresh()->product_category_id);
    }

    public function test_a_nonexistent_product_category_id_is_rejected(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            'product_category_id' => 99999,
        ]));

        $response->assertSessionHasErrors(['product_category_id']);
        $this->assertSame(0, Product::count());
    }

    public function test_index_sorts_by_name_by_default(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.products.store'), $this->baseProductPayload(['name' => 'Zebra Cola']));
        $this->post(route('master.products.store'), $this->baseProductPayload(['name' => 'Apel Jus']));
        $this->post(route('master.products.store'), $this->baseProductPayload(['name' => 'Mangga Jus']));

        $response = $this->get(route('master.products.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Master/Products/Index')
            ->where('products.data.0.name', 'Apel Jus')
            ->where('products.data.1.name', 'Mangga Jus')
            ->where('products.data.2.name', 'Zebra Cola'),
        );
    }

    public function test_index_paginates_at_twenty_per_page(): void
    {
        $this->actingAsAuthorizedUser();
        for ($i = 1; $i <= 25; $i++) {
            $this->post(route('master.products.store'), $this->baseProductPayload([
                'name' => 'Produk '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]));
        }

        $firstPage = $this->get(route('master.products.index'));
        $firstPage->assertInertia(fn ($page) => $page
            ->has('products.data', 20)
            ->where('products.total', 25)
            ->where('products.current_page', 1)
            ->where('products.last_page', 2),
        );

        $secondPage = $this->get(route('master.products.index', ['page' => 2]));
        $secondPage->assertInertia(fn ($page) => $page
            ->has('products.data', 5)
            ->where('products.current_page', 2),
        );
    }

    public function test_index_search_filters_by_name_or_barcode(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.products.store'), $this->baseProductPayload(['name' => 'Kopi Arabika', 'barcode' => '8991001']));
        $this->post(route('master.products.store'), $this->baseProductPayload(['name' => 'Teh Hijau', 'barcode' => '8992002']));
        $this->post(route('master.products.store'), $this->baseProductPayload(['name' => 'Susu UHT', 'barcode' => '8991003']));

        // Cocok lewat NAMA (mengandung "Kopi").
        $byName = $this->get(route('master.products.index', ['q' => 'Kopi']));
        $byName->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Kopi Arabika'),
        );

        // Cocok lewat BARCODE (mengandung "8991"), dua produk berbeda nama.
        $byBarcode = $this->get(route('master.products.index', ['q' => '8991']));
        $byBarcode->assertInertia(fn ($page) => $page
            ->has('products.data', 2)
            ->where('products.total', 2),
        );

        // Tidak cocok apa pun -> daftar kosong, bukan error.
        $noMatch = $this->get(route('master.products.index', ['q' => 'Nonexistent']));
        $noMatch->assertInertia(fn ($page) => $page->where('products.total', 0));
    }
}
