<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\DiningTable;
use App\Models\Member;
use App\Models\NoteTemplate;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penajaman UX "akses pengelolaan fitur opsional" -- menu sidebar &
 * link "Kelola [fitur]" hanya tampil kalau saklarnya ON, TAPI ini murni
 * tampilan: data lama tidak hilang & halamannya sendiri tetap bisa
 * diakses langsung lewat URL berapa pun status saklarnya (lihat
 * HandleInertiaRequests::share() dan AuthenticatedLayout.jsx).
 */
class FeatureFlagVisibilityTest extends TestCase
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
        $role->permissions()->attach([
            Permission::create(['key' => 'master-data.manage', 'label' => 'master-data.manage', 'group' => 'Test'])->id,
            Permission::create(['key' => 'company-settings.manage', 'label' => 'company-settings.manage', 'group' => 'Test'])->id,
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_feature_flags_shared_prop_reflects_company_setting_state(): void
    {
        $this->actingAsAuthorizedUser();
        // FoundationSeeder default: semua fitur opsional OFF.
        $response = $this->get(route('master.tables.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('featureFlags.member_enabled', false)
            ->where('featureFlags.table_enabled', false)
            ->where('featureFlags.note_enabled', false)
            ->where('featureFlags.variation_enabled', false)
            ->where('featureFlags.draft_enabled', false),
        );

        CompanySetting::current()->update([
            'member_enabled' => true,
            'table_enabled' => true,
        ]);

        // Tanpa logout -- request berikutnya langsung membawa nilai baru
        // (Inertia shared prop dievaluasi ulang tiap request, bukan di-cache
        // per sesi), jadi sidebar ikut berubah real-time setelah simpan.
        $response = $this->get(route('master.tables.index'));
        $response->assertInertia(fn ($page) => $page
            ->where('featureFlags.member_enabled', true)
            ->where('featureFlags.table_enabled', true)
            ->where('featureFlags.note_enabled', false),
        );
    }

    public function test_feature_flags_is_null_for_guest(): void
    {
        $response = $this->get(route('login'));

        $response->assertInertia(fn ($page) => $page->where('featureFlags', null));
    }

    public function test_master_pages_remain_accessible_via_direct_url_regardless_of_toggle_state(): void
    {
        $this->actingAsAuthorizedUser();
        // Semua saklar OFF (default FoundationSeeder) -- menu tidak tampil,
        // tapi halaman kelola tetap harus bisa diakses langsung lewat URL
        // (mis. admin perlu mengelola data lama). Ini keputusan yang
        // dijelaskan ke pengguna: hidden menu != inaccessible page.
        $this->get(route('master.members.index'))->assertOk();
        $this->get(route('master.tables.index'))->assertOk();
        $this->get(route('master.note-templates.index'))->assertOk();
        $this->get(route('master.products.create'))->assertOk();

        CompanySetting::current()->update([
            'member_enabled' => true,
            'table_enabled' => true,
            'note_enabled' => true,
            'variation_enabled' => true,
        ]);

        $this->get(route('master.members.index'))->assertOk();
        $this->get(route('master.tables.index'))->assertOk();
        $this->get(route('master.note-templates.index'))->assertOk();
        $this->get(route('master.products.create'))->assertOk();
    }

    public function test_existing_data_is_preserved_and_still_visible_when_a_feature_is_turned_off_then_on(): void
    {
        $this->actingAsAuthorizedUser();

        Member::create(['name' => 'Budi Santoso', 'is_active' => true]);
        Member::create(['name' => 'Citra Lestari', 'is_active' => true]);
        DiningTable::create(['name' => 'Meja 1', 'capacity' => 4]);
        NoteTemplate::create(['text' => 'Es sedikit']);

        CompanySetting::current()->update([
            'member_enabled' => true,
            'table_enabled' => true,
            'note_enabled' => true,
        ]);

        $this->assertSame(2, Member::count());
        $this->assertSame(1, DiningTable::count());
        $this->assertSame(1, NoteTemplate::count());

        // Matikan lagi -- data yang sudah ada TIDAK boleh terhapus, cuma
        // aksesnya (menu) yang tersembunyi. Halaman kelola tetap menunjukkan
        // baris-baris lama tersebut.
        CompanySetting::current()->update([
            'member_enabled' => false,
            'table_enabled' => false,
            'note_enabled' => false,
        ]);

        $this->assertSame(2, Member::count());
        $this->assertSame(1, DiningTable::count());
        $this->assertSame(1, NoteTemplate::count());

        $this->get(route('master.members.index'))->assertInertia(
            fn ($page) => $page->has('members', 2),
        );
        $this->get(route('master.tables.index'))->assertInertia(
            fn ($page) => $page->has('tables', 1),
        );
        $this->get(route('master.note-templates.index'))->assertInertia(
            fn ($page) => $page->has('noteTemplates', 1),
        );

        // Nyalakan lagi -- data yang sama muncul kembali (tidak pernah
        // benar-benar hilang, cuma menu-nya yang sempat disembunyikan).
        CompanySetting::current()->update([
            'member_enabled' => true,
            'table_enabled' => true,
            'note_enabled' => true,
        ]);

        $this->assertSame(2, Member::count());
        $this->assertSame(1, DiningTable::count());
        $this->assertSame(1, NoteTemplate::count());
    }

    public function test_product_form_props_include_variation_enabled_and_reflect_the_toggle(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('master.products.create'));
        $response->assertInertia(fn ($page) => $page
            ->component('Master/Products/Form')
            ->where('variationEnabled', false),
        );

        CompanySetting::current()->update(['variation_enabled' => true]);

        $response = $this->get(route('master.products.create'));
        $response->assertInertia(fn ($page) => $page->where('variationEnabled', true));
    }

    public function test_product_variations_are_preserved_and_still_returned_when_variation_feature_is_turned_off_then_on(): void
    {
        $this->actingAsAuthorizedUser();
        CompanySetting::current()->update(['variation_enabled' => true]);

        $this->post(route('master.products.store'), [
            'name' => 'Kopi Susu',
            'barcode' => '',
            'sell_price' => 15000,
            'tax_rate_id' => '',
            'is_active' => true,
            'components' => [],
            'variations' => [
                ['name' => 'Gelas Besar', 'additional_price' => 2000, 'is_active' => true, 'components' => []],
            ],
        ]);
        $product = Product::where('name', 'Kopi Susu')->firstOrFail();
        $this->assertSame(1, $product->variations()->count());

        // Matikan fitur -- editor variasi disembunyikan di form, tapi baris
        // `product_variations` yang sudah ada TIDAK dihapus.
        CompanySetting::current()->update(['variation_enabled' => false]);
        $this->assertSame(1, $product->variations()->count());

        $response = $this->get(route('master.products.edit', $product));
        $response->assertInertia(fn ($page) => $page
            ->where('variationEnabled', false)
            ->has('product.variations', 1),
        );

        // Nyalakan lagi -- data yang sama masih ada & tetap dikirim ke form.
        CompanySetting::current()->update(['variation_enabled' => true]);
        $this->assertSame(1, $product->variations()->count());
        $this->get(route('master.products.edit', $product))->assertInertia(
            fn ($page) => $page->has('product.variations', 1),
        );
    }
}
