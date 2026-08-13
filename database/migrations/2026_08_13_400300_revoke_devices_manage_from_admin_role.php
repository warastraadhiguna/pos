<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Role Developer (hidden super-admin, lihat rancangan yang disetujui)
     * -- MIGRASI B: cutover. Melepas `devices.manage` (Kelola Perangkat)
     * dari role Admin -- sesudah ini, cuma role Developer (lewat bypass
     * User::hasPermission(), lihat model) yang bisa mengelolanya.
     *
     * GUARD KERAS, bukan sekadar instruksi di runbook: kalau BELUM ADA
     * satu pun akun Developer saat migrasi ini jalan, migrasi DIBATALKAN
     * (exception, transaksi migrasi ini di-rollback -- migrasi
     * `..._400200_...` sebelumnya TETAP tersimpan, aman) alih-alih
     * melanjutkan dan mengunci Kelola Perangkat dari SEMUA ORANG. Operator
     * WAJIB menjalankan `php artisan developer:create` dulu di antara
     * migrasi `..._400200_...` dan migrasi ini, baru ulangi
     * `php artisan migrate`.
     *
     * Sama pola migrasi seed lain di file ini: hanya jalan di database
     * yang sudah pernah di-seed -- instalasi fresh (RolesAndPermissionsSeeder)
     * langsung ke state akhir (devices.manage tidak pernah di-attach ke
     * Admin), jadi tidak pernah ada akses existing yang bisa hilang di
     * instalasi baru, dan guard ini tidak relevan untuk instalasi baru.
     */
    public function up(): void
    {
        if (! Role::query()->exists()) {
            return;
        }

        $hasDeveloper = User::whereHas('role', fn ($q) => $q->where('is_developer', true))->exists();

        if (! $hasDeveloper) {
            throw new RuntimeException(
                'Migrasi dibatalkan: belum ada akun Developer. Jalankan `php artisan developer:create` '.
                'dulu, baru ulangi `php artisan migrate` -- supaya akses Kelola Perangkat tidak terkunci '.
                'untuk semua orang.',
            );
        }

        $admin = Role::where('name', 'Admin')->first();
        $devicesManage = Permission::where('key', 'devices.manage')->first();

        if ($admin && $devicesManage) {
            $admin->permissions()->detach($devicesManage->id);
        }
    }

    public function down(): void
    {
        $admin = Role::where('name', 'Admin')->first();
        $devicesManage = Permission::where('key', 'devices.manage')->first();

        if ($admin && $devicesManage) {
            $admin->permissions()->syncWithoutDetaching([$devicesManage->id]);
        }
    }
};
