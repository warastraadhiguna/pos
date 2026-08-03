<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductVariation;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    public function test_returns_products_with_barcode_tax_category_and_bom_components(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $taxRate = TaxRate::first();

        $item = Item::create([
            'sku' => 'BAHAN-01',
            'name' => 'Bahan Satu',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $account->id,
        ]);

        $product = Product::create([
            'name' => 'Produk Uji',
            'barcode' => '899000111222',
            'sell_price' => 12000,
            'tax_rate_id' => $taxRate->id,
            'is_active' => true,
        ]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 2, 'uom_id' => $pcs->id]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('data.0.barcode', '899000111222');
        $response->assertJsonPath('data.0.tax_rate.name', $taxRate->name);
        $response->assertJsonPath('data.0.components.0.item_id', $item->id);
        $response->assertJsonPath('data.0.components.0.qty', '2.0000');
        $response->assertJsonPath('data.0.components.0.uom.code', 'PCS');
        $response->assertJsonStructure(['meta' => ['synced_at']]);
    }

    /**
     * Variasi Berbayar (Tahap 1) ikut sync bareng produk (bukan endpoint
     * terpisah), pola identik `components` -- termasuk variasi NONAKTIF,
     * sama seperti is_active produk itu sendiri (client yang menyaring).
     */
    public function test_returns_products_with_variations(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        ProductVariation::create(['product_id' => $product->id, 'name' => 'Gelas Besar', 'additional_price' => 2000]);
        ProductVariation::create([
            'product_id' => $product->id,
            'name' => 'Nonaktif',
            'additional_price' => 1000,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $variations = collect($response->json('data.0.variations'));
        $this->assertCount(2, $variations);
        $active = $variations->firstWhere('name', 'Gelas Besar');
        $this->assertSame('2000.0000', $active['additional_price']);
        $this->assertTrue($active['is_active']);
        $inactive = $variations->firstWhere('name', 'Nonaktif');
        $this->assertFalse($inactive['is_active']);
    }

    public function test_includes_inactive_products_so_the_client_can_deactivate_them_locally(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Product::create(['name' => 'Nonaktif', 'sell_price' => 1000, 'is_active' => false]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('data.0.is_active', false);
    }

    /**
     * Reproduksi bug laporan mobile "pemilih variasi tidak muncul walau
     * sudah sync": admin membuka produk LAMA yang field intinya (nama,
     * harga, dst) TIDAK diubah, cuma menambah satu variasi baru lewat
     * Master\ProductController::update() -- persis alur form di web.
     * Eloquent TIDAK meng-update `updated_at` produk kalau tidak ada
     * kolomnya sendiri yang berubah (`save()` skip UPDATE query kalau
     * `isDirty()` false), jadi pull sync INKREMENTAL berikutnya (yang
     * memfilter `products.updated_at >= watermark`) melewatkan produk itu
     * sama sekali -- variasi barunya tidak pernah sampai ke HP walau ada
     * di database server.
     */
    public function test_updated_since_still_returns_a_product_whose_only_change_is_a_new_variation(): void
    {
        $role = \App\Models\Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach(
            \App\Models\Permission::create(['key' => 'master-data.manage', 'label' => 'master-data.manage', 'group' => 'Test'])->id,
        );
        $admin = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($admin, ['*']);

        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        DB::table('products')->where('id', $product->id)->update(['updated_at' => Carbon::parse('2020-01-01')]);

        $watermark = Carbon::now()->subMinute();

        // Alur admin sungguhan: PUT ke Master\ProductController::update()
        // dengan field produk PERSIS SAMA seperti sebelumnya (nama, harga,
        // dll tidak diubah admin), cuma menambah satu variasi baru.
        $updateResponse = $this->actingAs($admin)->put(route('master.products.update', $product), [
            'name' => 'Es Teh',
            'barcode' => '',
            'sell_price' => 5000,
            'tax_rate_id' => '',
            'is_active' => true,
            'components' => [],
            'variations' => [
                ['name' => 'Gelas Besar', 'additional_price' => 2000, 'is_active' => true, 'components' => []],
            ],
        ]);
        $updateResponse->assertRedirect(route('master.products.index'));

        $this->assertSame(1, $product->variations()->count());

        $response = $this->getJson('/api/v1/products?'.http_build_query(['updated_since' => $watermark->toIso8601String()]));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue(
            $names->contains('Es Teh'),
            'Produk dengan variasi baru harus tetap muncul di pull sync inkremental, bukan cuma di full pull.',
        );
    }

    public function test_updated_since_only_returns_products_changed_after_the_watermark(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $old = Product::create(['name' => 'Lama', 'sell_price' => 1000]);
        DB::table('products')->where('id', $old->id)->update(['updated_at' => Carbon::parse('2020-01-01')]);

        $watermark = Carbon::now()->subMinute();

        Product::create(['name' => 'Baru', 'sell_price' => 2000]);

        // http_build_query encodes the '+' in the ISO8601 UTC offset as
        // %2B — passing it unencoded (e.g. via naive string concatenation)
        // would decode back as a literal space and corrupt the timestamp,
        // exactly the bug this test's sibling below guards against.
        $response = $this->getJson('/api/v1/products?'.http_build_query(['updated_since' => $watermark->toIso8601String()]));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Baru'));
        $this->assertFalse($names->contains('Lama'));
    }

    public function test_a_malformed_updated_since_is_rejected_with_a_422_not_a_crash(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Simulates a client (or a naive HTTP call) sending the ISO8601 UTC
        // offset with a raw '+' that got query-string-decoded into a space
        // — this used to throw an uncaught DateMalformedStringException
        // (500) deep inside SyncWatermark before validation was added.
        $response = $this->getJson('/api/v1/products?updated_since=2026-07-08T01:54:53 00:00');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['updated_since']);
    }

    public function test_meta_includes_product_display_mode_defaulting_to_all(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('meta.product_display_mode', 'all');
    }

    public function test_meta_reflects_product_display_mode_changed_by_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        CompanySetting::current()->update(['product_display_mode' => 'search_only']);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('meta.product_display_mode', 'search_only');
    }

    public function test_meta_includes_store_identity_receipt_footer_and_kasir_display_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        CompanySetting::current()->update([
            'store_name' => 'Toko Maju Jaya',
            'store_address' => 'Jl. Merdeka No. 1',
            'store_phone' => '0812-3456-7890',
            'receipt_footer' => 'Sampai jumpa lagi!',
            'show_stock_on_button' => false,
            'show_product_image' => true,
            'payment_quick_amounts' => [2000, 15000],
            'mobile_print_receipt' => false,
            'member_enabled' => true,
            'table_enabled' => true,
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('meta.store_name', 'Toko Maju Jaya');
        $response->assertJsonPath('meta.store_address', 'Jl. Merdeka No. 1');
        $response->assertJsonPath('meta.store_phone', '0812-3456-7890');
        $response->assertJsonPath('meta.receipt_footer', 'Sampai jumpa lagi!');
        $response->assertJsonPath('meta.show_stock_on_button', false);
        $response->assertJsonPath('meta.show_product_image', true);
        $response->assertJsonPath('meta.payment_quick_amounts', [2000, 15000]);
        $response->assertJsonPath('meta.mobile_print_receipt', false);
        $response->assertJsonPath('meta.member_enabled', true);
        $response->assertJsonPath('meta.table_enabled', true);
    }

    public function test_meta_defaults_receipt_footer_and_store_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('meta.store_name', null);
        $response->assertJsonPath('meta.receipt_footer', 'Terima kasih atas kunjungan Anda');
        $response->assertJsonPath('meta.show_stock_on_button', true);
        $response->assertJsonPath('meta.show_product_image', false);
        $response->assertJsonPath('meta.payment_quick_amounts', [5000, 10000, 20000, 50000, 100000]);
        $response->assertJsonPath('meta.mobile_print_receipt', true);
        // Fitur baru — default false, sama disiplinnya dengan mobile_print_receipt:
        // tidak boleh tiba-tiba aktif di toko manapun tanpa admin sadar mengaktifkannya.
        $response->assertJsonPath('meta.member_enabled', false);
        $response->assertJsonPath('meta.table_enabled', false);
        $response->assertJsonPath('meta.note_enabled', false);
        $response->assertJsonPath('meta.variation_enabled', false);
        $response->assertJsonPath('meta.draft_enabled', false);
    }

    public function test_meta_reflects_variation_enabled_turned_on_by_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        CompanySetting::current()->update(['variation_enabled' => true]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('meta.variation_enabled', true);
    }

    public function test_meta_reflects_draft_enabled_turned_on_by_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        CompanySetting::current()->update(['draft_enabled' => true]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('meta.draft_enabled', true);
    }

    public function test_includes_image_url_and_hash_when_product_has_an_image(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $product = Product::create(['name' => 'Ada Gambar', 'sell_price' => 1000]);
        DB::table('products')->where('id', $product->id)->update([
            'image_path' => "products/{$product->id}/thumb_abc123.jpg",
            'image_hash' => 'abc123',
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertStringContainsString("products/{$product->id}/thumb_abc123.jpg", $row['image_url']);
        $this->assertSame('abc123', $row['image_hash']);
        // Versi web (~600px) HANYA untuk admin -- tidak pernah ikut payload
        // sync mobile sama sekali, bahkan sebagai field null yang tidak
        // dipakai (mobile tidak butuh tahu field itu ada).
        $this->assertArrayNotHasKey('image_url_web', $row);
    }

    public function test_image_url_and_hash_are_null_when_product_has_no_image(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        Product::create(['name' => 'Tanpa Gambar', 'sell_price' => 1000]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('data.0.image_url', null);
        $response->assertJsonPath('data.0.image_hash', null);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(401);
    }

    public function test_stock_endpoint_returns_producible_qty_per_product(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $warehouse = Warehouse::first();

        $item = Item::create([
            'sku' => 'BAHAN-STOK',
            'name' => 'Bahan Stok',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $account->id,
        ]);
        $product = Product::create(['name' => 'Produk Stok', 'sell_price' => 5000, 'is_active' => true]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 2, 'uom_id' => $pcs->id]);

        app(InventoryService::class)->recordInbound($item, $warehouse, 10, 100, $product, '2026-07-01');

        $response = $this->getJson('/api/v1/products/stock?warehouse_id='.$warehouse->id);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('product_id', $product->id);
        // 10 unit bahan, 2 per produk -> 5 producible.
        $this->assertSame(5, $row['producible_qty']);
        $response->assertJsonStructure(['meta' => ['as_of']]);
    }

    public function test_stock_endpoint_returns_null_producible_qty_for_products_without_stocked_components(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $product = Product::create(['name' => 'Jasa', 'sell_price' => 1000, 'is_active' => true]);

        $response = $this->getJson('/api/v1/products/stock?warehouse_id='.Warehouse::first()->id);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('product_id', $product->id);
        $this->assertNull($row['producible_qty']);
    }

    public function test_stock_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/products/stock');

        $response->assertStatus(401);
    }
}
