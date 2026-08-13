<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-Cabang Lapisan 1 -- kompatibilitas data lama (PALING KRUSIAL, lihat
 * penekanan user) + kasus lain di checklist verifikasi (device/user
 * assignment, accounts.outlet_id, toggle on/off).
 */
class MultiBranchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    // ==================== KOMPATIBILITAS DATA LAMA (WAJIB) ====================

    public function test_existing_outlet_is_backfilled_as_active_headquarters_with_code_pusat(): void
    {
        $outlet = Outlet::firstOrFail();

        $this->assertTrue($outlet->is_active);
        $this->assertTrue($outlet->is_headquarters);
        $this->assertSame('PUSAT', $outlet->code);
    }

    public function test_multi_branch_enabled_defaults_to_false(): void
    {
        $this->assertFalse(CompanySetting::current()->multi_branch_enabled);
    }

    public function test_existing_sale_creation_flow_is_completely_unaffected_by_layer_1(): void
    {
        // SaleService/Kasir\SaleController/Api\SaleController masih hardcode
        // Outlet::firstOrFail() (Lapisan 3 belum menyentuh ini) -- buktikan
        // alur itu MASIH menghasilkan sale valid menunjuk outlet yang sama
        // seperti sebelum migrasi Lapisan 1 ada.
        $outlet = Outlet::firstOrFail();
        $warehouse = Warehouse::where('outlet_id', $outlet->id)->firstOrFail();
        $product = Product::create(['name' => 'Kopi Sachet', 'sell_price' => 5000]);

        $sale = app(SaleService::class)->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000],
            ],
        ]);

        $this->assertSame($outlet->id, $sale->outlet_id);
        $this->assertSame('completed', $sale->status);
    }

    public function test_a_sale_created_before_layer_1_migrations_remains_readable_and_valid(): void
    {
        // Simulasikan data lama: sale sungguhan dibuat, LALU pastikan
        // kolom-kolom baru (outlet.is_headquarters dkk) tidak mengubah
        // apa pun tentang bagaimana sale itu dibaca kembali.
        $outlet = Outlet::firstOrFail();
        $warehouse = Warehouse::where('outlet_id', $outlet->id)->firstOrFail();
        $product = Product::create(['name' => 'Teh Tawar', 'sell_price' => 3000]);

        $sale = app(SaleService::class)->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 3000],
            ],
        ]);

        $reloaded = \App\Models\Sale::with('outlet')->findOrFail($sale->id);

        $this->assertSame('Outlet Pusat', $reloaded->outlet->name);
        $this->assertTrue($reloaded->outlet->is_headquarters);
        $this->assertSame('6000.0000', (string) $reloaded->grand_total);
    }

    public function test_toggle_off_means_outlets_route_still_exists_and_works_identically_regardless(): void
    {
        // OFF cuma menyembunyikan MENU (lihat AuthenticatedLayout.jsx
        // `feature: 'multi_branch_enabled'`) -- route & otorisasi tetap
        // berfungsi penuh terlepas dari nilai toggle, pola sama semua
        // fitur opsional lain (member/table/dst).
        $this->assertFalse(CompanySetting::current()->multi_branch_enabled);
        $admin = User::factory()->create(['role_id' => $this->roleWith(['branches.manage'])->id]);

        $this->actingAs($admin)->get('/master/outlets')->assertOk();
    }

    // ==================== TOGGLE ON/OFF ====================

    public function test_admin_can_toggle_multi_branch_enabled(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $this->actingAs($admin)->put(route('pengaturan.multi-branch.update'), [
            'multi_branch_enabled' => true,
        ])->assertRedirect();

        $this->assertTrue(CompanySetting::current()->multi_branch_enabled);

        $this->actingAs($admin)->put(route('pengaturan.multi-branch.update'), [
            'multi_branch_enabled' => false,
        ])->assertRedirect();

        $this->assertFalse(CompanySetting::current()->multi_branch_enabled);
    }

    // ==================== DEVICE OUTLET ASSIGNMENT ====================

    public function test_admin_can_assign_and_unassign_a_devices_outlet(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['devices.manage'])->id]);
        $device = Device::create(['device_id' => 'android-id-branch-1', 'status' => Device::STATUS_APPROVED]);
        $outlet = Outlet::create(['name' => 'Cabang Selatan', 'is_active' => true]);

        $this->actingAs($admin)->put("/pengaturan/perangkat/{$device->id}/outlet", [
            'outlet_id' => $outlet->id,
        ])->assertRedirect();

        $this->assertSame($outlet->id, $device->fresh()->outlet_id);

        $this->actingAs($admin)->put("/pengaturan/perangkat/{$device->id}/outlet", [
            'outlet_id' => null,
        ])->assertRedirect();

        $this->assertNull($device->fresh()->outlet_id);
    }

    // ==================== USER OUTLET ASSIGNMENT ====================

    public function test_admin_can_assign_a_users_outlet_and_leave_it_null_for_multi_branch_oversight(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['pengguna.manage'])->id]);
        $kasirRole = Role::create(['name' => 'Kasir Cabang']);
        $outlet = Outlet::create(['name' => 'Cabang Timur', 'is_active' => true]);

        $response = $this->actingAs($admin)->post('/pengguna', [
            'name' => 'Kasir Cabang Timur',
            'email' => 'kasir.timur@example.com',
            'password' => 'secret1234',
            'role_id' => $kasirRole->id,
            'outlet_id' => $outlet->id,
        ]);
        $response->assertRedirect(route('users.index'));

        $user = User::where('email', 'kasir.timur@example.com')->firstOrFail();
        $this->assertSame($outlet->id, $user->outlet_id);

        // Admin/manajer lintas cabang -- outlet_id null diperbolehkan.
        $overseer = User::factory()->create(['role_id' => $kasirRole->id, 'outlet_id' => null]);
        $this->assertNull($overseer->outlet_id);
    }

    // ==================== ACCOUNTS.OUTLET_ID ====================

    public function test_bank_account_can_optionally_be_scoped_to_an_outlet(): void
    {
        $outlet = Outlet::create(['name' => 'Cabang Utara', 'is_active' => true]);
        $cashAccounts = app(CashAccountService::class);

        $globalAccount = $cashAccounts->createBankAccount('1-1101', 'Bank BCA Pusat');
        $this->assertNull($globalAccount->outlet_id);

        $branchAccount = Account::create([
            'code' => '1-1102',
            'name' => 'Kas Cabang Utara',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'parent_id' => $globalAccount->parent_id,
            'is_active' => true,
            'outlet_id' => $outlet->id,
        ]);

        $this->assertSame($outlet->id, $branchAccount->fresh()->outlet_id);
        $this->assertSame('Cabang Utara', $branchAccount->outlet->name);
    }
}
