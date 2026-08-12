<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap the `devices.manage` permission for the hidden Device
     * Binding admin page (/pengaturan/perangkat, not in navGroups -- see
     * routes/web.php) -- Admin-only, same conditional pattern as
     * coa.manage/expense/kas-bank permission migrations: only created +
     * attached here on an already-seeded database; on a fresh install,
     * RolesAndPermissionsSeeder (updated alongside this migration) creates
     * and attaches it during `db:seed`.
     */
    public function up(): void
    {
        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'devices.manage'],
                ['label' => 'Kelola Perangkat', 'group' => 'Pengaturan'],
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
