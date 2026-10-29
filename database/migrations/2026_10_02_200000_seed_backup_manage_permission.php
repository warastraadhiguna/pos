<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Permission baru `backup.manage` (fitur Backup Database, lihat
     * rancangan yang disetujui) -- diberikan ke Owner & Admin (keduanya
     * dianggap level yang wajar utk kewenangan backup), SENGAJA TIDAK ke
     * Manajer/Kasir secara default (bisa diberikan belakangan kapan saja
     * lewat UI "Kelola Role & Izin" kalau ternyata diperlukan).
     *
     * Pola PERSIS migrasi permission lain (mis.
     * 2026_10_01_100800_seed_consignment_account_and_permission.php): hanya
     * jalan di database yang SUDAH pernah di-seed -- instalasi fresh
     * dilayani langsung oleh RolesAndPermissionsSeeder (diperbarui
     * berbarengan).
     */
    public function up(): void
    {
        if (! Role::query()->exists()) {
            return;
        }

        $permission = Permission::firstOrCreate(
            ['key' => 'backup.manage'],
            ['label' => 'Backup Database', 'group' => 'Pengaturan'],
        );

        Role::whereIn('name', ['Owner', 'Admin'])->get()->each(
            fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
        );
    }

    public function down(): void
    {
        //
    }
};
