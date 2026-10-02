<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Role baru "Owner" -- akses PENUH (semua permission yang bisa
     * diedit lewat UI "Kelola Role & Izin", sama persis set Admin hari
     * ini), diminta terpisah dari Admin karena rencananya Admin akan
     * dibatasi belakangan (bukan bagian migrasi ini).
     *
     * SENGAJA TIDAK diberi permission `devices.manage`/`system.manage`
     * (is_developer_only) -- itu reserved utk role Developer tersembunyi
     * (lihat RolesAndPermissionsSeeder), dan RoleController::permissionGroups()
     * memang tidak pernah menampilkan keduanya ke role mana pun yang bisa
     * diedit lewat UI (Permission::where('is_developer_only', false)) --
     * kalau dipaksa diberikan di sini, akan otomatis lenyap lagi begitu
     * ada yang membuka/menyimpan ulang form Owner lewat UI (sync() hanya
     * mengirim checkbox yang terlihat).
     *
     * Pola PERSIS migrasi role Developer
     * (2026_08_13_400200_seed_developer_role_and_system_manage_permission.php):
     * guard fresh-install (seeder yang sudah diperbarui menangani itu),
     * migrasi ini HANYA utk instalasi yang sudah ada.
     */
    public function up(): void
    {
        if (! Role::query()->exists()) {
            return;
        }

        $owner = Role::firstOrCreate(['name' => 'Owner']);
        $owner->permissions()->sync(
            Permission::where('is_developer_only', false)->pluck('id'),
        );
    }

    public function down(): void
    {
        //
    }
};
