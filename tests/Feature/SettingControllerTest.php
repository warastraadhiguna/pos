<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\CompanySettingLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        // FoundationSeeder default (produksi): ppn_active=false. Test
        // class ini menguji fitur TOGGLE-nya sendiri dan setiap skenario
        // di bawah mengasumsikan mulai dari true (lihat masing-masing
        // test) — di-set eksplisit di sini alih-alih mengandalkan default seed.
        CompanySetting::current()->update(['ppn_active' => true]);
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

    public function test_admin_can_view_the_settings_page(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->get('/pengaturan');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Index')
            ->where('ppnActive', true)
            ->where('productDisplayMode', 'all')
            ->where('storeName', null)
            ->where('receiptFooter', 'Terima kasih atas kunjungan Anda')
            ->where('showStockOnButton', true)
            ->where('showProductImage', false)
            ->where('paymentQuickAmounts', [5000, 10000, 20000, 50000, 100000]),
        );
    }

    public function test_admin_can_update_store_identity_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/identitas-toko', [
            'store_name' => 'Toko Maju Jaya',
            'store_address' => 'Jl. Merdeka No. 1',
            'store_phone' => '0812-3456-7890',
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $fresh = CompanySetting::current()->fresh();
        $this->assertSame('Toko Maju Jaya', $fresh->store_name);
        $this->assertSame('Jl. Merdeka No. 1', $fresh->store_address);
        $this->assertSame('0812-3456-7890', $fresh->store_phone);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_store_identity_fields_can_be_cleared_back_to_null(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);
        CompanySetting::current()->update(['store_name' => 'Toko Lama']);

        $response = $this->actingAs($admin)->put('/pengaturan/identitas-toko', [
            'store_name' => '',
            'store_address' => null,
            'store_phone' => null,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        // '' divalidasi sebagai string kosong yang valid (nullable), bukan
        // dipaksa null -- tapi tetap konsisten dengan "boleh dikosongkan".
        $fresh = CompanySetting::current()->fresh();
        $this->assertTrue($fresh->store_name === '' || $fresh->store_name === null);
        $this->assertNull($fresh->store_address);
        $this->assertNull($fresh->store_phone);
    }

    public function test_non_admin_cannot_update_store_identity(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/identitas-toko', ['store_name' => 'Hacked']);

        $response->assertForbidden();
        $this->assertNull(CompanySetting::current()->fresh()->store_name);
    }

    public function test_admin_can_update_receipt_footer_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/struk', [
            'receipt_footer' => 'Sampai jumpa lagi!',
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertSame('Sampai jumpa lagi!', CompanySetting::current()->fresh()->receipt_footer);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_receipt_footer_can_be_cleared_to_null(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/struk', ['receipt_footer' => null]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertNull(CompanySetting::current()->fresh()->receipt_footer);
    }

    public function test_admin_can_update_kasir_display_toggles_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);
        $this->assertTrue(CompanySetting::current()->show_stock_on_button);
        $this->assertFalse(CompanySetting::current()->show_product_image);

        $response = $this->actingAs($admin)->put('/pengaturan/tampilan-kasir', [
            'show_stock_on_button' => false,
            'show_product_image' => true,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $fresh = CompanySetting::current()->fresh();
        $this->assertFalse($fresh->show_stock_on_button);
        $this->assertTrue($fresh->show_product_image);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_non_admin_cannot_update_kasir_display_toggles(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/tampilan-kasir', [
            'show_stock_on_button' => false,
            'show_product_image' => true,
        ]);

        $response->assertForbidden();
        $this->assertTrue(CompanySetting::current()->fresh()->show_stock_on_button);
    }

    public function test_admin_can_change_product_display_mode_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $this->assertSame('all', CompanySetting::current()->product_display_mode);

        $response = $this->actingAs($admin)->put('/pengaturan/tampilan-produk', ['product_display_mode' => 'search_only']);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertSame('search_only', CompanySetting::current()->fresh()->product_display_mode);
        // Murni preferensi tampilan -- BUKAN keputusan berstatus hukum
        // seperti PPN, jadi sengaja tidak dicatat ke company_setting_logs.
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_invalid_product_display_mode_value_is_rejected(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/tampilan-produk', ['product_display_mode' => 'bukan-mode-valid']);

        $response->assertSessionHasErrors(['product_display_mode']);
        $this->assertSame('all', CompanySetting::current()->fresh()->product_display_mode);
    }

    public function test_non_admin_cannot_update_product_display_mode(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/tampilan-produk', ['product_display_mode' => 'search_only']);

        $response->assertForbidden();
        $this->assertSame('all', CompanySetting::current()->fresh()->product_display_mode);
    }

    public function test_admin_toggling_ppn_updates_the_setting_and_logs_who_and_when(): void
    {
        $admin = User::factory()->create([
            'name' => 'Kepala Toko',
            'role_id' => $this->roleWith(['company-settings.manage'])->id,
        ]);

        $this->assertTrue(CompanySetting::current()->ppn_active);

        $response = $this->actingAs($admin)->put('/pengaturan/ppn', ['ppn_active' => false]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertFalse(CompanySetting::current()->fresh()->ppn_active);

        $this->assertSame(1, CompanySettingLog::count());
        $log = CompanySettingLog::first();
        $this->assertFalse($log->ppn_active);
        $this->assertSame($admin->id, $log->changed_by_user_id);
        $this->assertNotNull($log->created_at);
        $this->assertSame('Kepala Toko', $log->changedBy->name);
    }

    public function test_submitting_the_same_value_does_not_create_a_duplicate_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        // Nilai saat ini sudah true (default FoundationSeeder) — submit true lagi.
        $this->actingAs($admin)->put('/pengaturan/ppn', ['ppn_active' => true]);

        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_history_shows_multiple_changes_in_order(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $this->actingAs($admin)->put('/pengaturan/ppn', ['ppn_active' => false]);
        $this->actingAs($admin)->put('/pengaturan/ppn', ['ppn_active' => true]);

        $this->assertSame(2, CompanySettingLog::count());

        $response = $this->actingAs($admin)->get('/pengaturan');
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Index')
            ->where('ppnActive', true)
            ->has('logs', 2),
        );
    }

    public function test_admin_can_update_payment_quick_amounts_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/nominal-bayar', [
            'payment_quick_amounts' => [50000, 20000, 100000],
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        // Tersimpan TERURUT (kecil ke besar), bukan urutan input admin.
        $this->assertSame([20000, 50000, 100000], CompanySetting::current()->fresh()->payment_quick_amounts);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_payment_quick_amounts_rejects_non_positive_or_non_integer_values(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/nominal-bayar', [
            'payment_quick_amounts' => [5000, 0, -1000, 'abc'],
        ]);

        $response->assertSessionHasErrors([
            'payment_quick_amounts.1',
            'payment_quick_amounts.2',
            'payment_quick_amounts.3',
        ]);
        $this->assertSame(
            [5000, 10000, 20000, 50000, 100000],
            CompanySetting::current()->fresh()->payment_quick_amounts,
        );
    }

    public function test_payment_quick_amounts_rejects_duplicate_values(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/nominal-bayar', [
            'payment_quick_amounts' => [10000, 10000],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(
            [5000, 10000, 20000, 50000, 100000],
            CompanySetting::current()->fresh()->payment_quick_amounts,
        );
    }

    public function test_payment_quick_amounts_rejects_more_than_eight_values(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/nominal-bayar', [
            'payment_quick_amounts' => [1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000],
        ]);

        $response->assertSessionHasErrors(['payment_quick_amounts']);
        $this->assertSame(
            [5000, 10000, 20000, 50000, 100000],
            CompanySetting::current()->fresh()->payment_quick_amounts,
        );
    }

    public function test_payment_quick_amounts_rejects_an_empty_list(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/nominal-bayar', [
            'payment_quick_amounts' => [],
        ]);

        $response->assertSessionHasErrors(['payment_quick_amounts']);
    }

    public function test_null_payment_quick_amounts_falls_back_to_a_sensible_default(): void
    {
        CompanySetting::current()->update(['payment_quick_amounts' => null]);

        $this->assertSame(
            [5000, 10000, 20000, 50000, 100000],
            CompanySetting::current()->fresh()->payment_quick_amounts,
        );
    }

    public function test_non_admin_cannot_update_payment_quick_amounts(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/nominal-bayar', [
            'payment_quick_amounts' => [1000],
        ]);

        $response->assertForbidden();
        $this->assertSame(
            [5000, 10000, 20000, 50000, 100000],
            CompanySetting::current()->fresh()->payment_quick_amounts,
        );
    }

    public function test_non_admin_cannot_view_the_settings_page(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $this->actingAs($kasir)->get('/pengaturan')->assertForbidden();
    }

    public function test_non_admin_cannot_update_ppn(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/ppn', ['ppn_active' => false]);

        $response->assertForbidden();
        $this->assertTrue(CompanySetting::current()->ppn_active);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_user_without_any_role_is_forbidden(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $this->actingAs($user)->get('/pengaturan')->assertForbidden();
    }
}
