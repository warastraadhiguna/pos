<?php

namespace Tests\Feature;

use App\Models\CompanySettingLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Role Developer (hidden super-admin) -- kasus verifikasi WAJIB dari
 * checklist user, huruf (a)-(i).
 */
class DeveloperRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pola SAMA `db:seed` sungguhan (DatabaseSeeder) -- instalasi
        // fresh, tanpa satu pun user.
        $this->seed(FoundationSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function roleByName(string $name): Role
    {
        return Role::where('name', $name)->firstOrFail();
    }

    private function userWithRole(string $roleName): User
    {
        return User::factory()->create(['role_id' => $this->roleByName($roleName)->id]);
    }

    // ==================== (a) Developer bypass walau pivot kosong ====================

    public function test_developer_role_bypasses_every_permission_check_even_with_an_empty_pivot(): void
    {
        $developer = $this->userWithRole('Developer');

        $this->assertSame(0, $developer->role->permissions()->count(), 'Role Developer sengaja diseed TANPA baris permission apa pun.');

        // Bypass tanpa syarat -- termasuk permission BISNIS biasa (seolah
        // superset Admin) dan permission developer-only, DAN bahkan key
        // yang sama sekali tidak pernah ada sebagai baris Permission --
        // membuktikan ini bypass sungguhan, bukan "kebetulan lolos lookup".
        $this->assertTrue($developer->hasPermission('laporan.view'));
        $this->assertTrue($developer->hasPermission('coa.manage'));
        $this->assertTrue($developer->hasPermission('devices.manage'));
        $this->assertTrue($developer->hasPermission('system.manage'));
        $this->assertTrue($developer->hasPermission('kunci-yang-tidak-pernah-ada'));
    }

    public function test_developer_permission_keys_returns_every_permission_for_frontend_nav_gating(): void
    {
        $developer = $this->userWithRole('Developer');

        $keys = $developer->permissionKeys();

        $this->assertSame(Permission::count(), count($keys), 'Frontend nav digerbangi lewat daftar ini -- kalau tidak ikut bypass, sidebar Developer akan kosong walau backend sudah mengizinkan.');
        $this->assertContains('system.manage', $keys);
        $this->assertContains('devices.manage', $keys);
    }

    public function test_non_developer_role_is_not_affected_by_the_bypass(): void
    {
        $kasir = $this->userWithRole('Kasir');

        $this->assertTrue($kasir->hasPermission('kasir.access'));
        $this->assertFalse($kasir->hasPermission('devices.manage'));
        $this->assertFalse($kasir->hasPermission('kunci-yang-tidak-pernah-ada'));
    }

    // ==================== (b) Admin edit role sendiri tidak bisa centang system.manage/devices.manage ====================

    public function test_admin_editing_the_admin_role_does_not_see_developer_only_permissions_as_options(): void
    {
        $admin = $this->userWithRole('Admin');
        $adminRole = $this->roleByName('Admin');

        $response = $this->actingAs($admin)->get(route('roles.edit', $adminRole));
        $response->assertOk();

        $groups = $response->viewData('page')['props']['permissionGroups'];
        $allKeysShown = collect($groups)->flatten(1)->pluck('key');

        $this->assertNotContains('system.manage', $allKeysShown, 'system.manage tidak boleh pernah muncul sebagai checkbox untuk role manapun.');
        $this->assertNotContains('devices.manage', $allKeysShown, 'devices.manage tidak boleh pernah muncul sebagai checkbox untuk role manapun.');
    }

    public function test_admin_cannot_grant_developer_only_permission_to_the_admin_role_via_raw_post(): void
    {
        $admin = $this->userWithRole('Admin');
        $adminRole = $this->roleByName('Admin');
        $systemManageId = Permission::where('key', 'system.manage')->value('id');
        $devicesManageId = Permission::where('key', 'devices.manage')->value('id');
        $legitimateIds = $adminRole->permissions()->pluck('permissions.id')->all();

        // POST mentah menyertakan ID permission developer-only secara
        // langsung -- BUKAN lewat UI (yang sudah tidak menampilkannya sama
        // sekali di test sebelumnya) -- mensimulasikan admin yang tahu ID-
        // nya dan mencoba lewat request manual.
        $response = $this->actingAs($admin)->put(route('roles.update', $adminRole), [
            'name' => 'Admin',
            'permission_ids' => [...$legitimateIds, $systemManageId, $devicesManageId],
        ]);

        $response->assertSessionHasErrors();
        $this->assertFalse(
            $adminRole->fresh()->permissions->contains('key', 'system.manage'),
            'Validasi harus menolak SELURUH request -- system.manage tidak boleh ter-attach.',
        );
        $this->assertFalse($adminRole->fresh()->permissions->contains('key', 'devices.manage'));
    }

    // ==================== (c) /roles/{id developer}/edit -> 404 ====================

    public function test_direct_url_access_to_the_developer_role_returns_404(): void
    {
        $admin = $this->userWithRole('Admin');
        $developerRole = $this->roleByName('Developer');

        $this->actingAs($admin)->get(route('roles.edit', $developerRole))->assertNotFound();
        $this->actingAs($admin)->put(route('roles.update', $developerRole), ['name' => 'Diubah Paksa', 'permission_ids' => []])->assertNotFound();
        $this->actingAs($admin)->delete(route('roles.destroy', $developerRole))->assertNotFound();

        $this->assertSame('Developer', $developerRole->fresh()->name, 'Update paksa lewat ID langsung harus ditolak, nama tidak berubah.');
        $this->assertNotNull(Role::find($developerRole->id), 'Delete paksa lewat ID langsung harus ditolak, role masih ada.');
    }

    public function test_developer_role_is_absent_from_the_role_management_index(): void
    {
        $admin = $this->userWithRole('Admin');

        $response = $this->actingAs($admin)->get(route('roles.index'));
        $roleNames = collect($response->viewData('page')['props']['roles'])->pluck('name');

        $this->assertNotContains('Developer', $roleNames);
        $this->assertContains('Admin', $roleNames);
    }

    // ==================== (d) POST role_id=Developer ke user -> ditolak ====================

    public function test_creating_a_user_with_the_developer_role_id_is_rejected_server_side(): void
    {
        $admin = $this->userWithRole('Admin');
        $developerRole = $this->roleByName('Developer');

        $response = $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Percobaan Eskalasi',
            'email' => 'eskalasi@example.test',
            'password' => 'password1234',
            'role_id' => $developerRole->id,
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertNull(User::where('email', 'eskalasi@example.test')->first(), 'Tidak boleh ada user tersimpan dengan role Developer lewat jalur ini.');
    }

    public function test_updating_an_existing_user_to_the_developer_role_id_is_rejected_server_side(): void
    {
        $admin = $this->userWithRole('Admin');
        $target = $this->userWithRole('Kasir');
        $developerRole = $this->roleByName('Developer');

        $response = $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $developerRole->id,
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertSame('Kasir', $target->fresh()->role->name, 'Role user tidak boleh berubah jadi Developer lewat jalur ini.');
    }

    // ==================== (e) Migrasi B THROW & batal kalau belum ada Developer ====================

    private function requireMigration(string $filename): object
    {
        return require database_path("migrations/{$filename}");
    }

    public function test_migration_b_throws_and_makes_no_change_when_no_developer_account_exists_yet(): void
    {
        // Simulasikan database YANG SUDAH ADA sebelum fitur ini: Admin
        // masih pegang devices.manage secara langsung (RolesAndPermissionsSeeder
        // yang sudah diperbarui TIDAK lagi memberikannya dari awal, jadi
        // re-attach manual di sini untuk menguji migrasi 400300 itu SENDIRI,
        // terlepas dari state akhir yang seeder hasilkan).
        $admin = $this->roleByName('Admin');
        $devicesManage = Permission::where('key', 'devices.manage')->firstOrFail();
        $admin->permissions()->syncWithoutDetaching([$devicesManage->id]);

        $this->assertSame(0, User::whereHas('role', fn ($q) => $q->where('is_developer', true))->count());

        $migration = $this->requireMigration('2026_08_13_400300_revoke_devices_manage_from_admin_role.php');

        try {
            $migration->up();
            $this->fail('Migrasi B harus THROW kalau belum ada akun Developer.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('developer:create', $e->getMessage());
        }

        // Migrasi A (400000-400200, sudah jalan lewat RefreshDatabase +
        // seeder di atas) tetap tersimpan -- role Developer & permission
        // system.manage masih ada. Dan efek migrasi B sendiri TIDAK
        // terjadi sebagian pun -- Admin masih pegang devices.manage persis
        // seperti sebelum up() dipanggil.
        $this->assertNotNull(Role::where('name', 'Developer')->where('is_developer', true)->first());
        $this->assertNotNull(Permission::where('key', 'system.manage')->first());
        $this->assertTrue($admin->fresh()->permissions->contains('key', 'devices.manage'), 'Migrasi dibatalkan -- devices.manage TIDAK boleh terlepas dari Admin.');
    }

    public function test_migration_b_detaches_devices_manage_from_admin_once_a_developer_account_exists(): void
    {
        $admin = $this->roleByName('Admin');
        $devicesManage = Permission::where('key', 'devices.manage')->firstOrFail();
        $admin->permissions()->syncWithoutDetaching([$devicesManage->id]);

        $this->userWithRole('Developer');

        $migration = $this->requireMigration('2026_08_13_400300_revoke_devices_manage_from_admin_role.php');
        $migration->up();

        $this->assertFalse($admin->fresh()->permissions->contains('key', 'devices.manage'));
    }

    // ==================== (f) Setelah developer:create + Migrasi B: admin kehilangan, developer punya ====================

    public function test_after_seeding_final_state_admin_is_blocked_from_devices_and_system_routes_while_developer_is_allowed(): void
    {
        // RolesAndPermissionsSeeder (fresh install) SUDAH state akhir --
        // ekuivalen "sesudah Migrasi A+B" tanpa jendela transisi (lihat
        // rancangan: instalasi fresh tidak punya akses existing yang bisa
        // hilang, jadi langsung ke state akhir).
        $admin = $this->userWithRole('Admin');
        $developer = $this->userWithRole('Developer');

        $this->actingAs($admin)->get(route('devices.index'))->assertForbidden();
        $this->actingAs($developer)->get(route('devices.index'))->assertOk();

        $this->actingAs($admin)->put(route('pengaturan.multi-branch.update'), ['multi_branch_enabled' => true])->assertForbidden();
        $this->actingAs($developer)->put(route('pengaturan.multi-branch.update'), ['multi_branch_enabled' => true])->assertRedirect(route('pengaturan.index'));

        $this->actingAs($admin)->put(route('pengaturan.device-binding-grace-period.update'), ['action' => 'disable'])->assertForbidden();
        $this->actingAs($developer)->put(route('pengaturan.device-binding-grace-period.update'), ['action' => 'disable'])->assertRedirect(route('pengaturan.index'));

        // Sekadar memastikan Admin TIDAK kehilangan permission bisnis lain
        // yang tidak berkaitan -- cuma 2 hal spesifik ini yang berubah.
        $this->actingAs($admin)->get(route('laporan.neraca'))->assertOk();
    }

    /**
     * Penyempurnaan lanjutan -- toggle ON/OFF Meja/Catatan/Variasi/Draft
     * ikut pindah ke system.manage (developer-only), pola IDENTIK
     * Multi-Cabang/grace period di atas. TAPI kelola ISI fitur (Kelola
     * Meja/Template Catatan/Produk & Variasi) TETAP `master-data.manage`
     * (admin) -- route/permission yang SAMA SEKALI TERPISAH, jadi harus
     * tetap bisa diakses Admin walau 4 toggle di atas sudah 403.
     */
    public function test_the_four_feature_toggles_are_developer_only_while_their_data_management_pages_stay_admin(): void
    {
        $admin = $this->userWithRole('Admin');
        $developer = $this->userWithRole('Developer');

        $this->actingAs($admin)->put(route('pengaturan.meja.update'), ['table_enabled' => true])->assertForbidden();
        $this->actingAs($admin)->put(route('pengaturan.catatan.update'), ['note_enabled' => true])->assertForbidden();
        $this->actingAs($admin)->put(route('pengaturan.variasi.update'), ['variation_enabled' => true])->assertForbidden();
        $this->actingAs($admin)->put(route('pengaturan.draft.update'), ['draft_enabled' => true])->assertForbidden();

        $this->actingAs($developer)->put(route('pengaturan.meja.update'), ['table_enabled' => true])->assertRedirect(route('pengaturan.index'));
        $this->actingAs($developer)->put(route('pengaturan.catatan.update'), ['note_enabled' => true])->assertRedirect(route('pengaturan.index'));
        $this->actingAs($developer)->put(route('pengaturan.variasi.update'), ['variation_enabled' => true])->assertRedirect(route('pengaturan.index'));
        $this->actingAs($developer)->put(route('pengaturan.draft.update'), ['draft_enabled' => true])->assertRedirect(route('pengaturan.index'));

        $this->assertTrue(\App\Models\CompanySetting::current()->table_enabled);
        $this->assertTrue(\App\Models\CompanySetting::current()->note_enabled);
        $this->assertTrue(\App\Models\CompanySetting::current()->variation_enabled);
        $this->assertTrue(\App\Models\CompanySetting::current()->draft_enabled);

        // Kelola ISI fitur -- Admin (master-data.manage) TETAP penuh akses,
        // terlepas dari 4 toggle di atas sudah developer-only.
        $this->assertTrue($admin->hasPermission('master-data.manage'));
        $this->actingAs($admin)->get(route('master.tables.index'))->assertOk();
        $this->actingAs($admin)->get(route('master.note-templates.index'))->assertOk();
        $this->actingAs($admin)->get(route('master.products.index'))->assertOk();
    }

    // ==================== (g) Audit tetap utuh; 2 toggle sekarang tercatat ====================

    public function test_actions_performed_by_a_developer_are_attributed_normally_like_any_other_user(): void
    {
        $developer = $this->userWithRole('Developer');
        $expenseAccount = \App\Models\Account::where('code', '5-3000')->first()
            ?? \App\Models\Account::create(['code' => '5-3000', 'name' => 'Listrik', 'type' => 'expense', 'normal_balance' => 'debit']);
        $outlet = \App\Models\Outlet::first();

        $response = $this->actingAs($developer)->post(route('beban.store'), [
            'outlet_id' => $outlet->id,
            'expense_account_id' => $expenseAccount->id,
            'date' => '2026-08-13',
            'amount' => 50000,
            'payment_method' => 'cash',
            'description' => 'Dicatat oleh developer',
        ]);
        $response->assertRedirect(route('beban.index'));

        $expense = \App\Models\Expense::latest('id')->firstOrFail();
        $this->assertSame($developer->id, $expense->created_by_user_id, 'Atribusi created_by_user_id harus tetap terisi normal untuk aksi developer -- tidak ada lubang audit.');
    }

    public function test_multi_branch_toggle_and_grace_period_changes_are_now_logged_to_company_setting_logs(): void
    {
        $developer = $this->userWithRole('Developer');

        $this->assertSame(0, CompanySettingLog::count());

        $this->actingAs($developer)->put(route('pengaturan.multi-branch.update'), ['multi_branch_enabled' => true])
            ->assertRedirect(route('pengaturan.index'));

        $multiBranchLog = CompanySettingLog::where('setting_key', 'multi_branch_enabled')->firstOrFail();
        $this->assertTrue($multiBranchLog->multi_branch_enabled);
        $this->assertSame($developer->id, $multiBranchLog->changed_by_user_id);

        $this->actingAs($developer)->put(route('pengaturan.device-binding-grace-period.update'), ['action' => 'extend', 'days' => 7])
            ->assertRedirect(route('pengaturan.index'));

        $graceLog = CompanySettingLog::where('setting_key', 'device_binding_grace_period')->firstOrFail();
        $this->assertNotNull($graceLog->device_binding_grace_period_ends_at);
        $this->assertSame($developer->id, $graceLog->changed_by_user_id);

        // Submit ulang NILAI YANG SAMA untuk multi-branch tidak menambah
        // baris baru -- pola sama PPN (dedupe by value), sekarang berlaku
        // juga di sini.
        $countBefore = CompanySettingLog::count();
        $this->actingAs($developer)->put(route('pengaturan.multi-branch.update'), ['multi_branch_enabled' => true]);
        $this->assertSame($countBefore, CompanySettingLog::count());
    }

    // ==================== (h) Developer & akunnya tak muncul di Kelola Role, Kelola Pengguna, dropdown role ====================

    public function test_developer_account_and_role_are_hidden_from_user_and_role_management_uis(): void
    {
        $admin = $this->userWithRole('Admin');
        $developer = $this->userWithRole('Developer');

        $usersIndex = $this->actingAs($admin)->get(route('users.index'));
        $userEmails = collect($usersIndex->viewData('page')['props']['users'])->pluck('email');
        $this->assertNotContains($developer->email, $userEmails, 'Akun Developer tidak boleh muncul di Kelola Pengguna.');
        $this->assertContains($admin->email, $userEmails);

        $createForm = $this->actingAs($admin)->get(route('users.create'));
        $roleNamesInDropdown = collect($createForm->viewData('page')['props']['roles'])->pluck('name');
        $this->assertNotContains('Developer', $roleNamesInDropdown, 'Role Developer tidak boleh muncul di dropdown Tambah Pengguna.');

        $editForm = $this->actingAs($admin)->get(route('users.edit', $this->userWithRole('Kasir')));
        $roleNamesInEditDropdown = collect($editForm->viewData('page')['props']['roles'])->pluck('name');
        $this->assertNotContains('Developer', $roleNamesInEditDropdown, 'Role Developer tidak boleh muncul di dropdown Edit Pengguna.');

        $rolesIndex = $this->actingAs($admin)->get(route('roles.index'));
        $this->assertNotContains('Developer', collect($rolesIndex->viewData('page')['props']['roles'])->pluck('name'));
    }

    public function test_direct_url_access_to_a_developer_users_edit_page_returns_404(): void
    {
        $admin = $this->userWithRole('Admin');
        $developer = $this->userWithRole('Developer');

        $this->actingAs($admin)->get(route('users.edit', $developer))->assertNotFound();
        $this->actingAs($admin)->put(route('users.update', $developer), ['name' => 'Diubah Paksa', 'email' => $developer->email, 'role_id' => $this->roleByName('Admin')->id])->assertNotFound();
        $this->actingAs($admin)->delete(route('users.destroy', $developer))->assertNotFound();

        $this->assertSame('Developer', $developer->fresh()->role->name);
        $this->assertNotNull(User::find($developer->id));
    }

    // ==================== (i) Manajer/Kasir tak terdampak; data lama valid; fresh install ke state akhir ====================

    public function test_manajer_and_kasir_permissions_are_unaffected_by_this_feature(): void
    {
        $manajer = $this->roleByName('Manajer');
        $kasir = $this->roleByName('Kasir');

        // Belum pernah punya devices.manage/system.manage sebelum fitur
        // ini (dikecualikan eksplisit sejak awal di seeder) -- masih
        // begitu sekarang, bukan regresi baru.
        $this->assertFalse($manajer->permissions->contains('key', 'devices.manage'));
        $this->assertFalse($manajer->permissions->contains('key', 'system.manage'));
        $this->assertFalse($kasir->permissions->contains('key', 'devices.manage'));
        $this->assertFalse($kasir->permissions->contains('key', 'system.manage'));

        // Permission bisnis yang memang seharusnya mereka punya tidak
        // tersentuh sama sekali.
        $this->assertTrue($manajer->permissions->contains('key', 'laporan.view'));
        $this->assertTrue($kasir->permissions->contains('key', 'kasir.access'));
    }

    public function test_fresh_install_seeds_the_final_state_directly_with_no_transition_window(): void
    {
        $admin = $this->roleByName('Admin');
        $developer = $this->roleByName('Developer');

        $this->assertFalse($admin->permissions->contains('key', 'devices.manage'), 'Instalasi fresh tidak pernah memberi devices.manage ke Admin.');
        $this->assertFalse($admin->permissions->contains('key', 'system.manage'));

        $this->assertTrue($developer->is_developer);
        $this->assertSame(0, $developer->permissions()->count());

        $devicesManage = Permission::where('key', 'devices.manage')->firstOrFail();
        $systemManage = Permission::where('key', 'system.manage')->firstOrFail();
        $this->assertTrue($devicesManage->is_developer_only);
        $this->assertTrue($systemManage->is_developer_only);
    }
}
