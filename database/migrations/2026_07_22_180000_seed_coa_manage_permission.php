<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap the `coa.manage` permission for the Chart of Accounts
     * list/manage feature -- Admin-only (touches core accounting
     * structure, same sensitivity tier as modal.manage).
     *
     * Same conditional pattern as the prior features: only created +
     * attached here if roles already exist (an already-seeded database);
     * on a fresh install, RolesAndPermissionsSeeder (updated alongside
     * this migration) creates and attaches it during `db:seed`.
     */
    public function up(): void
    {
        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'coa.manage'],
                ['label' => 'Chart of Accounts', 'group' => 'Transaksi'],
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
