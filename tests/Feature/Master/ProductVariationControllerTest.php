<?php

namespace Tests\Feature\Master;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ProductVariationComponent;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\DraftSyncService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRUD variasi (Tahap 1, harga-saja) -- dikelola EMBEDDED di form Produk
 * (bukan halaman terpisah), lihat Master\ProductController::syncVariations().
 * File test TERPISAH dari ProductControllerTest (yang fokus ke
 * gambar/kategori) supaya kelompok assertion ini gampang ditemukan.
 */
class ProductVariationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Diseed untuk SEMUA test di sini (bukan cuma test transaksi) --
        // konsisten dengan NoteTemplateControllerTest/TableControllerTest,
        // dan test_removing_a_variation_already_used_in_a_sale... butuh
        // Outlet/Warehouse/CompanySetting sungguhan lewat SaleService.
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

    private function baseProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Es Teh',
            'barcode' => '',
            'sell_price' => 5000,
            'tax_rate_id' => '',
            'is_active' => true,
            'components' => [],
            'variations' => [],
        ], $overrides);
    }

    /**
     * BOM per variasi (Tahap 2) butuh Item/Uom sungguhan yang lolos
     * `exists:items,id`/`exists:uoms,id` -- dibuat langsung lewat Eloquent
     * (bukan lewat FoundationSeeder), pola identik
     * SaleServiceTest::makeStockedItem().
     */
    private function makeItem(string $sku): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $sku,
            'costing_type' => 'stocked',
            'base_uom_id' => Uom::where('code', 'PCS')->firstOrFail()->id,
            'purchase_uom_id' => Uom::where('code', 'PCS')->firstOrFail()->id,
            'standard_cost' => 0,
            'inventory_account_id' => Account::where('code', '1-1200')->firstOrFail()->id,
        ]);
    }

    public function test_variations_can_be_created_alongside_a_new_product(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [
                ['name' => 'Gelas Besar', 'additional_price' => 2000, 'is_active' => true],
                ['name' => 'Gelas Kecil', 'additional_price' => 0, 'is_active' => true],
            ],
        ]))->assertRedirect(route('master.products.index'));

        $product = Product::where('name', 'Es Teh')->firstOrFail();
        $this->assertSame(2, $product->variations()->count());
        $this->assertSame(0, bccomp($product->variations()->where('name', 'Gelas Besar')->firstOrFail()->additional_price, '2000', 4));
    }

    public function test_updating_a_product_adds_a_new_variation(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.products.store'), $this->baseProductPayload());
        $product = Product::where('name', 'Es Teh')->firstOrFail();

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'variations' => [
                ['name' => 'Gelas Besar', 'additional_price' => 2000, 'is_active' => true],
            ],
        ]))->assertRedirect(route('master.products.index'));

        $this->assertSame(1, $product->variations()->count());
        $this->assertSame('Gelas Besar', $product->variations()->firstOrFail()->name);
    }

    /**
     * Baris dengan `id` yang cocok -> UPDATE di tempat, bukan hapus+buat
     * ulang -- membuktikan id-nya benar-benar dipertahankan (bukan
     * kebetulan cocok karena auto-increment berurutan).
     */
    public function test_updating_a_product_edits_an_existing_variation_by_id(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [['name' => 'Gelas Besar', 'additional_price' => 2000, 'is_active' => true]],
        ]));
        $product = Product::where('name', 'Es Teh')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'variations' => [
                ['id' => $variation->id, 'name' => 'Gelas Jumbo', 'additional_price' => 3000, 'is_active' => true],
            ],
        ]))->assertRedirect(route('master.products.index'));

        $this->assertSame(1, $product->variations()->count());
        $this->assertSame($variation->id, $product->variations()->firstOrFail()->id);
        $this->assertSame('Gelas Jumbo', $variation->fresh()->name);
        $this->assertSame(0, bccomp($variation->fresh()->additional_price, '3000', 4));
    }

    /**
     * Variasi yang DIHILANGKAN dari daftar submit (dan tidak pernah dipakai
     * transaksi) benar-benar dihapus dari database -- bukan cuma
     * dinonaktifkan.
     */
    public function test_removing_an_unused_variation_from_the_list_deletes_it(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [['name' => 'Gelas Besar', 'additional_price' => 2000, 'is_active' => true]],
        ]));
        $product = Product::where('name', 'Es Teh')->firstOrFail();

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'variations' => [],
        ]))->assertRedirect(route('master.products.index'));

        $this->assertSame(0, ProductVariation::count());
    }

    /**
     * INTI dari syncVariations(): variasi yang sudah pernah dipakai
     * transaksi TIDAK BOLEH hilang begitu saja saat admin mengedit
     * produknya (sale_line_variations.variation_id restrictOnDelete akan
     * memblokir DELETE) -- syncVariations() menangkap itu dan
     * menonaktifkan sebagai gantinya, TANPA menggagalkan seluruh
     * penyimpanan produk.
     */
    public function test_removing_a_variation_already_used_in_a_sale_deactivates_it_instead_of_failing(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [['name' => 'Gelas Besar', 'additional_price' => 2000, 'is_active' => true]],
        ]));
        $product = Product::where('name', 'Es Teh')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $outlet = Outlet::first();
        $warehouse = Warehouse::first();
        $sales = new SaleService(new InventoryService(), new PostingService(), new CashAccountService(), new DraftSyncService());
        $sales->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-07-31',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 7000,
                'variations' => [['variation_id' => $variation->id]],
            ]],
        ]);

        $response = $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'variations' => [],
        ]));

        $response->assertRedirect(route('master.products.index'));
        $response->assertSessionDoesntHaveErrors();
        // Baris TETAP ada (tidak terhapus, tidak melanggar FK), tapi nonaktif.
        $this->assertSame(1, ProductVariation::count());
        $this->assertFalse($variation->fresh()->is_active);
    }

    public function test_variation_name_is_required(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [['name' => '', 'additional_price' => 1000, 'is_active' => true]],
        ]));

        $response->assertSessionHasErrors(['variations.0.name']);
        $this->assertSame(0, Product::count());
    }

    public function test_variation_additional_price_cannot_be_negative(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [['name' => 'Diskon', 'additional_price' => -500, 'is_active' => true]],
        ]));

        $response->assertSessionHasErrors(['variations.0.additional_price']);
        $this->assertSame(0, Product::count());
    }

    // --- Tahap 2: BOM per variasi (product_variation_components) ---

    public function test_variation_components_can_be_created_alongside_a_new_product(): void
    {
        $this->actingAsAuthorizedUser();
        $gelas = $this->makeItem('GELAS-PLASTIK-1');
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [[
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [
                    ['item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $pcs->id],
                ],
            ]],
        ]))->assertRedirect(route('master.products.index'));

        $variation = ProductVariation::where('name', 'Bawa Pulang')->firstOrFail();
        $this->assertSame(1, $variation->components()->count());
        $component = $variation->components()->firstOrFail();
        $this->assertSame($gelas->id, $component->item_id);
        $this->assertSame(0, bccomp($component->qty, '1', 4));
    }

    /**
     * Variasi TANPA komponen (`components` kosong/absen) tetap valid --
     * identik perilaku Tahap 1, HPP-nya nanti 0 saat dijual (lihat
     * SaleServiceTest::test_hpp_total_is_unaffected_by_variations_in_tahap_1).
     */
    public function test_variation_without_components_is_still_valid(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [['name' => 'Ekstra Manis', 'additional_price' => 500, 'is_active' => true]],
        ]))->assertRedirect(route('master.products.index'));

        $variation = ProductVariation::where('name', 'Ekstra Manis')->firstOrFail();
        $this->assertSame(0, $variation->components()->count());
    }

    /**
     * BOM variasi BOLEH blind delete+recreate (beda dari variasi itu
     * sendiri, yang diff-based upsert) -- lihat docblock
     * ProductController::syncVariations(): tabel ini tidak direferensikan
     * balik oleh apa pun, jadi tidak kena constraint yang memaksa upsert.
     * Diuji lewat UPDATE yang MENGGANTI SELURUH isi komponen sebuah
     * variasi yang sudah ada.
     */
    public function test_updating_a_product_replaces_a_variations_components_entirely(): void
    {
        $this->actingAsAuthorizedUser();
        $gelas = $this->makeItem('GELAS-PLASTIK-2');
        $sedotan = $this->makeItem('SEDOTAN-1');
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [[
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [
                    ['item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $pcs->id],
                ],
            ]],
        ]));
        $product = Product::where('name', 'Es Teh')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $this->assertSame(1, $variation->components()->count());

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'variations' => [[
                'id' => $variation->id,
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [
                    ['item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $pcs->id],
                    ['item_id' => $sedotan->id, 'qty' => 2, 'uom_id' => $pcs->id],
                ],
            ]],
        ]))->assertRedirect(route('master.products.index'));

        $this->assertSame(2, $variation->components()->count());
        $this->assertSame(
            [$gelas->id, $sedotan->id],
            $variation->components()->orderBy('id')->pluck('item_id')->all(),
        );
    }

    /**
     * Mengirim `components: []` untuk variasi yang SEBELUMNYA punya bahan
     * -- bahan dihapus seluruhnya (admin sengaja mengubahnya jadi variasi
     * harga-saja lagi), variasinya sendiri TETAP ada.
     */
    public function test_updating_a_product_can_remove_all_components_from_a_variation(): void
    {
        $this->actingAsAuthorizedUser();
        $gelas = $this->makeItem('GELAS-PLASTIK-3');
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [[
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [
                    ['item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $pcs->id],
                ],
            ]],
        ]));
        $product = Product::where('name', 'Es Teh')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'variations' => [[
                'id' => $variation->id,
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [],
            ]],
        ]))->assertRedirect(route('master.products.index'));

        $this->assertSame(1, ProductVariation::count());
        $this->assertSame(0, $variation->components()->count());
    }

    /**
     * Menghapus (cascadeOnDelete) sebuah variasi yang belum pernah dipakai
     * transaksi ikut menghapus komponennya -- BEDA dari variasi itu
     * sendiri yang bisa dibentur restrictOnDelete kalau sudah pernah
     * dipakai (lihat test_removing_a_variation_already_used_in_a_sale...),
     * tapi komponennya sendiri TIDAK PERNAH direferensikan oleh apa pun,
     * jadi selalu ikut cascade.
     */
    public function test_removing_a_variation_also_removes_its_components(): void
    {
        $this->actingAsAuthorizedUser();
        $gelas = $this->makeItem('GELAS-PLASTIK-4');
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [[
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [
                    ['item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $pcs->id],
                ],
            ]],
        ]));
        $product = Product::where('name', 'Es Teh')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $componentId = $variation->components()->firstOrFail()->id;

        $this->put(route('master.products.update', $product), $this->baseProductPayload([
            'variations' => [],
        ]))->assertRedirect(route('master.products.index'));

        $this->assertSame(0, ProductVariation::count());
        $this->assertSame(0, ProductVariationComponent::where('id', $componentId)->count());
    }

    public function test_variation_component_item_id_must_exist(): void
    {
        $this->actingAsAuthorizedUser();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [[
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [
                    ['item_id' => 999999, 'qty' => 1, 'uom_id' => $pcs->id],
                ],
            ]],
        ]));

        $response->assertSessionHasErrors(['variations.0.components.0.item_id']);
        $this->assertSame(0, Product::count());
    }

    public function test_variation_component_qty_must_be_positive(): void
    {
        $this->actingAsAuthorizedUser();
        $gelas = $this->makeItem('GELAS-PLASTIK-5');
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $response = $this->post(route('master.products.store'), $this->baseProductPayload([
            'variations' => [[
                'name' => 'Bawa Pulang',
                'additional_price' => 1000,
                'is_active' => true,
                'components' => [
                    ['item_id' => $gelas->id, 'qty' => 0, 'uom_id' => $pcs->id],
                ],
            ]],
        ]));

        $response->assertSessionHasErrors(['variations.0.components.0.qty']);
        $this->assertSame(0, Product::count());
    }
}
