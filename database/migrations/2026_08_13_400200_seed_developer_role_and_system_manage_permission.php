<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Role Developer (hidden super-admin, lihat rancangan yang disetujui)
     * -- MIGRASI A: aditif murni, nol risiko. Membuat role "Developer"
     * KOSONG (tanpa satu pun baris permission -- User::hasPermission()/
     * permissionKeys() bypass semuanya lewat flag is_developer, lihat
     * model User) dan permission `system.manage` (developer-only, dipakai
     * toggle Multi-Cabang & grace period Device Binding -- lihat migrasi
     * cutover terpisah untuk route-nya). `devices.manage` (Kelola
     * Perangkat) ditandai developer-only DI SINI juga, TAPI SENGAJA BELUM
     * dilepas dari Admin -- itu baru terjadi di migrasi cutover berikutnya
     * (bersyarat: harus sudah ada akun Developer dulu), supaya tidak ada
     * jendela waktu di mana Admin existing kehilangan akses sebelum siapa
     * pun bisa menggantikannya.
     *
     * Sama seperti migrasi seed permission lain di file ini (pola
     * `..._seed_branches_manage_permission.php`): hanya jalan di database
     * yang SUDAH pernah di-seed (Role::query()->exists()) -- instalasi
     * fresh dilayani langsung oleh RolesAndPermissionsSeeder (diperbarui
     * berbarengan) yang seed ke state akhir tanpa jendela transisi sama
     * sekali (tidak ada akses existing yang bisa hilang di instalasi baru).
     */
    public function up(): void
    {
        if (! Role::query()->exists()) {
            return;
        }

        $developer = Role::firstOrCreate(['name' => 'Developer']);
        if (! $developer->is_developer) {
            $developer->update(['is_developer' => true]);
        }

        Permission::firstOrCreate(
            ['key' => 'system.manage'],
            ['label' => 'Pengaturan Sistem (Developer)', 'group' => 'Pengaturan', 'is_developer_only' => true],
        );

        Permission::where('key', 'devices.manage')->update(['is_developer_only' => true]);
    }

    public function down(): void
    {
        //
    }
};
