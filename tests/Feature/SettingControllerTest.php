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
            ->where('paymentQuickAmounts', [5000, 10000, 20000, 50000, 100000])
            ->where('mobilePrintReceipt', true),
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

    public function test_mobile_print_receipt_defaults_to_true(): void
    {
        $this->assertTrue(CompanySetting::current()->mobile_print_receipt);
    }

    public function test_admin_can_turn_off_mobile_print_receipt_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/cetak-struk-mobile', [
            'mobile_print_receipt' => false,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertFalse(CompanySetting::current()->fresh()->mobile_print_receipt);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_non_admin_cannot_update_mobile_print_receipt(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/cetak-struk-mobile', [
            'mobile_print_receipt' => false,
        ]);

        $response->assertForbidden();
        $this->assertTrue(CompanySetting::current()->fresh()->mobile_print_receipt);
    }

    public function test_member_enabled_defaults_to_false(): void
    {
        $this->assertFalse(CompanySetting::current()->member_enabled);
    }

    public function test_admin_can_turn_on_member_enabled_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/member', [
            'member_enabled' => true,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertTrue(CompanySetting::current()->fresh()->member_enabled);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_non_admin_cannot_update_member_enabled(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/member', [
            'member_enabled' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse(CompanySetting::current()->fresh()->member_enabled);
    }

    public function test_table_enabled_defaults_to_false(): void
    {
        $this->assertFalse(CompanySetting::current()->table_enabled);
    }

    // Toggle Meja adalah pengaturan KERANGKA sistem (Role Developer, hidden
    // super-admin) -- digerbangi system.manage (developer-only), BUKAN lagi
    // company-settings.manage biasa. Lihat rancangan yang disetujui &
    // routes/web.php.
    public function test_a_user_with_system_manage_can_turn_on_table_enabled_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['system.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/meja', [
            'table_enabled' => true,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertTrue(CompanySetting::current()->fresh()->table_enabled);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_non_admin_cannot_update_table_enabled(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/meja', [
            'table_enabled' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse(CompanySetting::current()->fresh()->table_enabled);
    }

    public function test_note_enabled_defaults_to_false(): void
    {
        $this->assertFalse(CompanySetting::current()->note_enabled);
    }

    // Toggle Catatan -- pola sama Meja di atas, digerbangi system.manage
    // (developer-only) sejak Role Developer ada.
    public function test_a_user_with_system_manage_can_turn_on_note_enabled_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['system.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/catatan', [
            'note_enabled' => true,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertTrue(CompanySetting::current()->fresh()->note_enabled);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_non_admin_cannot_update_note_enabled(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/catatan', [
            'note_enabled' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse(CompanySetting::current()->fresh()->note_enabled);
    }

    public function test_variation_enabled_defaults_to_false(): void
    {
        $this->assertFalse(CompanySetting::current()->variation_enabled);
    }

    // Toggle Variasi -- pola sama Meja di atas, digerbangi system.manage
    // (developer-only) sejak Role Developer ada.
    public function test_a_user_with_system_manage_can_turn_on_variation_enabled_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['system.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/variasi', [
            'variation_enabled' => true,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertTrue(CompanySetting::current()->fresh()->variation_enabled);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_non_admin_cannot_update_variation_enabled(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/variasi', [
            'variation_enabled' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse(CompanySetting::current()->fresh()->variation_enabled);
    }

    public function test_draft_enabled_defaults_to_false(): void
    {
        $this->assertFalse(CompanySetting::current()->draft_enabled);
    }

    // Toggle Draft -- pola sama Meja di atas, digerbangi system.manage
    // (developer-only) sejak Role Developer ada.
    public function test_a_user_with_system_manage_can_turn_on_draft_enabled_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['system.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/draft', [
            'draft_enabled' => true,
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $this->assertTrue(CompanySetting::current()->fresh()->draft_enabled);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_non_admin_cannot_update_draft_enabled(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/draft', [
            'draft_enabled' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse(CompanySetting::current()->fresh()->draft_enabled);
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

    // --- QRIS (Tafsir A -- pencatatan) ---

    public function test_qris_enabled_defaults_to_false(): void
    {
        $this->assertFalse(CompanySetting::current()->qris_enabled);
        $this->assertNull(CompanySetting::current()->qris_cash_account_code);
    }

    public function test_admin_can_turn_on_qris_with_a_bank_account_without_creating_a_log_entry(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/qris', [
            'qris_enabled' => true,
            'qris_cash_account_code' => '1-1100',
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $fresh = CompanySetting::current()->fresh();
        $this->assertTrue($fresh->qris_enabled);
        $this->assertSame('1-1100', $fresh->qris_cash_account_code);
        $this->assertSame(0, CompanySettingLog::count());
    }

    public function test_enabling_qris_without_a_bank_account_is_rejected(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/qris', [
            'qris_enabled' => true,
        ]);

        $response->assertSessionHasErrors('qris_cash_account_code');
        $this->assertFalse(CompanySetting::current()->fresh()->qris_enabled);
    }

    public function test_enabling_qris_targeting_kas_is_rejected(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/qris', [
            'qris_enabled' => true,
            'qris_cash_account_code' => '1-1000',
        ]);

        $response->assertSessionHasErrors('qris_cash_account_code');
        $this->assertFalse(CompanySetting::current()->fresh()->qris_enabled);
    }

    public function test_enabling_qris_with_an_inactive_or_unrelated_account_is_rejected(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        // '1-1200' -- Persediaan, akun asset yang sah tapi BUKAN Kas/Bank.
        $response = $this->actingAs($admin)->put('/pengaturan/qris', [
            'qris_enabled' => true,
            'qris_cash_account_code' => '1-1200',
        ]);

        $response->assertSessionHasErrors('qris_cash_account_code');
    }

    public function test_admin_can_turn_off_qris_leaving_the_account_stored_for_next_time(): void
    {
        CompanySetting::current()->update([
            'qris_enabled' => true,
            'qris_cash_account_code' => '1-1100',
        ]);
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->put('/pengaturan/qris', [
            'qris_enabled' => false,
            'qris_cash_account_code' => '1-1100',
        ]);

        $response->assertRedirect(route('pengaturan.index'));
        $fresh = CompanySetting::current()->fresh();
        $this->assertFalse($fresh->qris_enabled);
        // Akun TETAP tersimpan (bukan dikosongkan) -- mengaktifkan lagi
        // nanti tidak perlu memilih ulang dari awal.
        $this->assertSame('1-1100', $fresh->qris_cash_account_code);
    }

    public function test_non_admin_cannot_update_qris(): void
    {
        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $response = $this->actingAs($kasir)->put('/pengaturan/qris', [
            'qris_enabled' => true,
            'qris_cash_account_code' => '1-1100',
        ]);

        $response->assertForbidden();
        $this->assertFalse(CompanySetting::current()->fresh()->qris_enabled);
    }

    public function test_settings_page_exposes_qris_fields_and_bank_only_accounts(): void
    {
        CompanySetting::current()->update([
            'qris_enabled' => true,
            'qris_cash_account_code' => '1-1100',
        ]);
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $response = $this->actingAs($admin)->get('/pengaturan');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Index')
            ->where('qrisEnabled', true)
            ->where('qrisCashAccountCode', '1-1100')
            ->where('bankAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1100')
                && ! collect($accounts)->pluck('code')->contains('1-1000')),
        );
    }
}
