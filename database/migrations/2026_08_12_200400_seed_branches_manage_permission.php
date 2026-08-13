<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap the `branches.manage` permission for "Kelola Cabang"
     * (Multi-Cabang Lapisan 1) -- Admin-only, separate from
     * `master-data.manage` because branch structure touches accounting/
     * cash-drawer scoping (poin 5 rancangan), same sensitivity tier as
     * `coa.manage`/`modal.manage`/`devices.manage`. Same conditional
     * pattern as those: only created + attached here on an already-seeded
     * database; on a fresh install, RolesAndPermissionsSeeder (updated
     * alongside this migration) creates and attaches it during `db:seed`.
     */
    public function up(): void
    {
        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'branches.manage'],
                ['label' => 'Kelola Cabang', 'group' => 'Pengaturan'],
            );

            Role::where('name', 'Admin')->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );
        }
    }

    public function down(): void
    {
        //
    }
};
