<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Identitas per-cabang (header struk) -- kasus verifikasi WAJIB dari
 * checklist user: tersimpan & tampil di struk cabang yang benar (web &
 * mobile), fallback ke pusat kalau cabang kosong, admin (BUKAN developer)
 * bisa edit, toggle multi-cabang tetap developer-only, kompatibilitas
 * multi_branch_enabled=false.
 */
class BranchReceiptIdentityTest extends TestCase
{
    use RefreshDatabase;

    private BranchService $branches;

    private SaleService $sales;

    private Outlet $headquarters;

    private Warehouse $headquartersWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->branches = new BranchService();
        $this->sales = app(SaleService::class);
        $this->headquarters = Outlet::first();
        $this->headquartersWarehouse = Warehouse::first();
    }

    private function enableMultiBranch(): void
    {
        CompanySetting::current()->update(['multi_branch_enabled' => true]);
    }

    /** @return array{0: Outlet, 1: Warehouse} */
    private function makeBranchWithWarehouse(string $name): array
    {
        $outlet = $this->branches->createOutlet([
            'name' => $name,
            'code' => null,
            'address' => null,
            'is_active' => true,
            'is_headquarters' => false,
        ]);

        return [$outlet, Warehouse::where('outlet_id', $outlet->id)->firstOrFail()];
    }

    private function makeSale(Outlet $outlet, Warehouse $warehouse, string $productName = 'Produk Test'): \App\Models\Sale
    {
        $product = Product::create(['name' => $productName, 'sell_price' => 10000]);

        return $this->sales->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-08-13',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);
    }

    private function userWithPermission(string $key): User
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach($permission->id);

        return User::factory()->create(['role_id' => $role->id]);
    }

    // ==================== BranchService::resolveReceiptIdentity() ====================

    public function test_headquarters_always_uses_global_identity_even_if_its_own_columns_are_populated(): void
    {
        CompanySetting::current()->update([
            'store_name' => 'Toko Pusat Global',
            'store_address' => 'Jl. Global No. 1',
            'store_phone' => '021-1111111',
            'receipt_footer' => 'Footer Global',
        ]);
        // Kalaupun baris outlet pusat kebetulan terisi (mis. field lama
        // yang diedit iseng), resolveReceiptIdentity() TIDAK BOLEH pernah
        // membacanya -- ini jaminan kompatibilitas, bukan kebetulan.
        $this->headquarters->update(['address' => 'Alamat Salah', 'phone' => '000', 'receipt_footer' => 'Salah']);

        $identity = $this->branches->resolveReceiptIdentity($this->headquarters->fresh());

        $this->assertSame('Toko Pusat Global', $identity['name']);
        $this->assertSame('Jl. Global No. 1', $identity['address']);
        $this->assertSame('021-1111111', $identity['phone']);
        $this->assertSame('Footer Global', $identity['receipt_footer']);
    }

    public function test_branch_uses_its_own_identity_when_fully_filled(): void
    {
        [$cabang] = $this->makeBranchWithWarehouse('Cabang Selatan');
        $cabang->update([
            'address' => 'Jl. Selatan No. 5',
            'phone' => '022-2222222',
            'receipt_footer' => 'Terima kasih sudah mampir Cabang Selatan',
        ]);

        $identity = $this->branches->resolveReceiptIdentity($cabang->fresh());

        $this->assertSame('Cabang Selatan', $identity['name']);
        $this->assertSame('Jl. Selatan No. 5', $identity['address']);
        $this->assertSame('022-2222222', $identity['phone']);
        $this->assertSame('Terima kasih sudah mampir Cabang Selatan', $identity['receipt_footer']);
    }

    public function test_branch_falls_back_to_global_per_field_when_its_own_fields_are_empty(): void
    {
        CompanySetting::current()->update([
            'store_address' => 'Alamat Global',
            'store_phone' => '021-0000000',
            'receipt_footer' => 'Footer Global',
        ]);
        // Cabang BARU -- name terisi (wajib saat dibuat), tapi
        // address/phone/receipt_footer belum sempat diisi admin.
        [$cabang] = $this->makeBranchWithWarehouse('Cabang Baru');

        $identity = $this->branches->resolveReceiptIdentity($cabang->fresh());

        $this->assertSame('Cabang Baru', $identity['name'], 'Nama cabang sendiri tetap dipakai -- selalu terisi sejak dibuat.');
        $this->assertSame('Alamat Global', $identity['address'], 'Field yang belum diisi cabang jatuh ke global -- struk tidak pernah tanpa identitas.');
        $this->assertSame('021-0000000', $identity['phone']);
        $this->assertSame('Footer Global', $identity['receipt_footer']);
    }

    // ==================== Struk web ====================

    public function test_web_receipt_uses_the_sales_own_branch_identity_regardless_of_who_is_viewing(): void
    {
        CompanySetting::current()->update(['store_name' => 'Toko Pusat', 'store_address' => 'Alamat Pusat']);
        [$cabang, $cabangWarehouse] = $this->makeBranchWithWarehouse('Cabang Timur');
        $cabang->update(['address' => 'Jl. Timur No. 9', 'phone' => '023-3333333']);

        $sale = $this->makeSale($cabang, $cabangWarehouse, 'Produk Cabang Timur');

        // Direprint oleh admin di PUSAT (session/permission berbeda dari
        // tempat transaksi terjadi) -- identitas HARUS tetap ikut cabang
        // ASAL transaksi, bukan pusat yang sedang membuka halaman ini.
        $viewer = $this->userWithPermission('penjualan.view');
        $response = $this->actingAs($viewer)->get(route('penjualan.receipt', $sale->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('store.name', 'Cabang Timur')
            ->where('store.address', 'Jl. Timur No. 9')
            ->where('store.phone', '023-3333333'),
        );
    }

    public function test_web_receipt_for_a_headquarters_sale_uses_global_identity(): void
    {
        CompanySetting::current()->update(['store_name' => 'Toko Pusat Sungguhan']);
        $sale = $this->makeSale($this->headquarters, $this->headquartersWarehouse);

        $viewer = $this->userWithPermission('penjualan.view');
        $response = $this->actingAs($viewer)->get(route('penjualan.receipt', $sale->id));

        $response->assertInertia(fn ($page) => $page->where('store.name', 'Toko Pusat Sungguhan'));
    }

    // ==================== CRUD identitas cabang: ADMIN, bukan Developer ====================

    public function test_a_real_admin_without_system_manage_can_update_branch_identity(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$cabang] = $this->makeBranchWithWarehouse('Cabang Barat');

        $admin = User::factory()->create(['role_id' => Role::where('name', 'Admin')->firstOrFail()->id]);
        // Sejak fitur Role Developer, Admin sungguhan TIDAK punya system.manage
        // sama sekali -- membuktikan CRUD identitas cabang murni jalur admin.
        $this->assertFalse($admin->hasPermission('system.manage'));
        $this->assertTrue($admin->hasPermission('branches.manage'));

        $response = $this->actingAs($admin)->put(route('master.outlets.update', $cabang), [
            'name' => $cabang->name,
            'code' => $cabang->code,
            'address' => 'Jl. Barat No. 3',
            'phone' => '024-4444444',
            'receipt_footer' => 'Footer Cabang Barat',
            'is_active' => true,
            'is_headquarters' => false,
        ]);

        $response->assertRedirect(route('master.outlets.index'));
        $cabang->refresh();
        $this->assertSame('Jl. Barat No. 3', $cabang->address);
        $this->assertSame('024-4444444', $cabang->phone);
        $this->assertSame('Footer Cabang Barat', $cabang->receipt_footer);
    }

    public function test_a_developer_only_permission_holder_is_not_required_to_edit_branch_identity(): void
    {
        // Sebaliknya: user TANPA system.manage (cuma branches.manage) tetap
        // bisa akses halaman edit -- tidak pernah butuh akses developer.
        [$cabang] = $this->makeBranchWithWarehouse('Cabang Utara');
        $user = $this->userWithPermission('branches.manage');

        $this->actingAs($user)->get(route('master.outlets.edit', $cabang))->assertOk();
    }

    // ==================== Satukan pengaturan identitas: form Cabang Pusat -> company_settings ====================

    public function test_editing_headquarters_via_kelola_cabang_saves_to_company_settings_not_outlet_columns(): void
    {
        $this->enableMultiBranch();
        $admin = $this->userWithPermission('branches.manage');

        $response = $this->actingAs($admin)->put(route('master.outlets.update', $this->headquarters), [
            'name' => $this->headquarters->name,
            'code' => $this->headquarters->code,
            'address' => $this->headquarters->address,
            'is_active' => true,
            'is_headquarters' => true,
            'store_name' => 'Toko Makmur Jaya',
            'store_address' => 'Jl. Pusat No. 1',
            'store_phone' => '021-5551234',
            'store_footer' => 'Terima kasih sudah belanja',
        ]);

        $response->assertRedirect(route('master.outlets.index'));

        $setting = CompanySetting::current()->fresh();
        $this->assertSame('Toko Makmur Jaya', $setting->store_name);
        $this->assertSame('Jl. Pusat No. 1', $setting->store_address);
        $this->assertSame('021-5551234', $setting->store_phone);
        $this->assertSame('Terima kasih sudah belanja', $setting->receipt_footer);

        // TIDAK ditulis ke kolom outlets sama sekali -- outlet pusat tidak
        // pernah punya baris "store_name", dan kolom phone/receipt_footer
        // miliknya sendiri (yang TIDAK dikirim di request ini) tetap kosong.
        $this->assertNull($this->headquarters->fresh()->phone);
        $this->assertNull($this->headquarters->fresh()->receipt_footer);

        // Bukti tuntas: resolveReceiptIdentity() (TIDAK diubah fitur ini)
        // sekarang mengembalikan persis nilai yang baru disimpan.
        $identity = $this->branches->resolveReceiptIdentity($this->headquarters->fresh());
        $this->assertSame('Toko Makmur Jaya', $identity['name']);
        $this->assertSame('Jl. Pusat No. 1', $identity['address']);
        $this->assertSame('021-5551234', $identity['phone']);
        $this->assertSame('Terima kasih sudah belanja', $identity['receipt_footer']);
    }

    public function test_editing_a_non_headquarters_branch_still_saves_to_its_own_outlet_columns_not_company_settings(): void
    {
        $this->enableMultiBranch();
        CompanySetting::current()->update(['store_name' => 'Toko Pusat Tak Boleh Berubah']);
        [$cabang] = $this->makeBranchWithWarehouse('Cabang Timur');
        $admin = $this->userWithPermission('branches.manage');

        // store_* SENGAJA ikut dikirim (mensimulasikan payload mentah/tidak
        // wajar) -- harus diabaikan total untuk cabang non-pusat.
        $response = $this->actingAs($admin)->put(route('master.outlets.update', $cabang), [
            'name' => $cabang->name,
            'address' => 'Jl. Timur No. 9',
            'phone' => '023-3333333',
            'receipt_footer' => 'Footer Cabang Timur',
            'is_active' => true,
            'is_headquarters' => false,
            'store_name' => 'Nama Nyasar',
            'store_phone' => '000-0000000',
        ]);

        $response->assertRedirect(route('master.outlets.index'));
        $cabang->refresh();
        $this->assertSame('Jl. Timur No. 9', $cabang->address);
        $this->assertSame('023-3333333', $cabang->phone);
        $this->assertSame('Footer Cabang Timur', $cabang->receipt_footer);

        $this->assertSame('Toko Pusat Tak Boleh Berubah', CompanySetting::current()->fresh()->store_name, 'store_* di payload cabang non-pusat harus diabaikan total.');
    }

    public function test_promoting_a_branch_to_headquarters_saves_its_submitted_identity_to_company_settings(): void
    {
        $this->enableMultiBranch();
        CompanySetting::current()->update(['store_name' => 'Toko Pusat Lama']);
        [$cabangBaru] = $this->makeBranchWithWarehouse('Cabang Yang Dipromosikan');
        $admin = $this->userWithPermission('branches.manage');

        $response = $this->actingAs($admin)->put(route('master.outlets.update', $cabangBaru), [
            'name' => $cabangBaru->name,
            'is_active' => true,
            'is_headquarters' => true, // promosi
            'store_name' => 'Toko Pusat Baru',
            'store_phone' => '021-9998888',
        ]);

        $response->assertRedirect(route('master.outlets.index'));
        $this->assertTrue($cabangBaru->fresh()->is_headquarters);
        $this->assertFalse($this->headquarters->fresh()->is_headquarters, 'Pusat lama otomatis lepas status (BranchService::setHeadquarters(), tidak diubah fitur ini).');

        $setting = CompanySetting::current()->fresh();
        $this->assertSame('Toko Pusat Baru', $setting->store_name);
        $this->assertSame('021-9998888', $setting->store_phone);
    }

    public function test_outlet_edit_page_provides_multi_branch_flag_and_current_store_identity_for_prefilling(): void
    {
        $this->enableMultiBranch();
        CompanySetting::current()->update(['store_name' => 'Toko Untuk Prefill']);
        $admin = $this->userWithPermission('branches.manage');

        $response = $this->actingAs($admin)->get(route('master.outlets.edit', $this->headquarters));

        $response->assertInertia(fn ($page) => $page
            ->where('multiBranchEnabled', true)
            ->where('storeIdentity.store_name', 'Toko Untuk Prefill'),
        );
    }

    // ==================== Sync mobile ====================

    /** @return array{0: User, 1: string} [user, bearer token] */
    private function loginMobileDevice(string $deviceId, ?Outlet $assignToOutlet): array
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        // Percobaan pertama -- device baru, pending (persis alur Device
        // Binding sungguhan).
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_id' => $deviceId,
        ])->assertStatus(403);

        $device = Device::where('device_id', $deviceId)->firstOrFail();
        $device->update(['status' => Device::STATUS_APPROVED, 'outlet_id' => $assignToOutlet?->id]);

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_id' => $deviceId,
        ])->assertOk();

        return [$user, $login->json('token')];
    }

    public function test_mobile_sync_meta_uses_the_devices_bound_branch_identity(): void
    {
        $this->enableMultiBranch();
        CompanySetting::current()->update(['store_name' => 'Toko Pusat', 'store_address' => 'Alamat Pusat']);
        [$cabang] = $this->makeBranchWithWarehouse('Cabang Mobile');
        $cabang->update(['address' => 'Jl. Mobile No. 7', 'phone' => '025-5555555', 'receipt_footer' => 'Footer Mobile']);

        [, $token] = $this->loginMobileDevice('device-'.Str::random(8), $cabang);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/products');

        $response->assertOk();
        $response->assertJsonPath('meta.store_name', 'Cabang Mobile');
        $response->assertJsonPath('meta.store_address', 'Jl. Mobile No. 7');
        $response->assertJsonPath('meta.store_phone', '025-5555555');
        $response->assertJsonPath('meta.receipt_footer', 'Footer Mobile');
    }

    public function test_mobile_sync_meta_falls_back_to_headquarters_identity_when_device_has_no_outlet(): void
    {
        $this->enableMultiBranch();
        CompanySetting::current()->update(['store_name' => 'Toko Pusat Fallback']);

        [, $token] = $this->loginMobileDevice('device-'.Str::random(8), null);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/products');

        $response->assertJsonPath('meta.store_name', 'Toko Pusat Fallback');
    }

    // ==================== Kompatibilitas: multi_branch_enabled=false ====================

    public function test_disabled_toggle_means_meta_and_receipt_always_use_global_identity_regardless_of_branch_data(): void
    {
        // multi_branch_enabled TETAP default false.
        $this->assertFalse(CompanySetting::current()->multi_branch_enabled);
        CompanySetting::current()->update(['store_name' => 'Toko Satu Lokasi']);

        // Sengaja tetap buat cabang dengan identitas sendiri DAN device
        // yang ditugaskan ke sana -- kalau toggle mati, semua ini harus
        // diabaikan total, identik perilaku sebelum Multi-Cabang ada.
        [$cabang] = $this->makeBranchWithWarehouse('Cabang Terabaikan');
        $cabang->update(['address' => 'Alamat Cabang', 'phone' => '099-9999999']);

        [, $token] = $this->loginMobileDevice('device-'.Str::random(8), $cabang);
        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/products');
        $response->assertJsonPath('meta.store_name', 'Toko Satu Lokasi');
        $response->assertJsonPath('meta.store_address', null);

        $sale = $this->makeSale($this->headquarters, $this->headquartersWarehouse);
        $viewer = $this->userWithPermission('penjualan.view');
        $this->actingAs($viewer)->get(route('penjualan.receipt', $sale->id))
            ->assertInertia(fn ($page) => $page->where('store.name', 'Toko Satu Lokasi'));
    }
}
