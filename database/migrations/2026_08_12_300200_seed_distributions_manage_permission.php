<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap the `distributions.manage` permission for "Distribusi
     * Stok" (Multi-Cabang Lapisan 2) -- grup "Transaksi", setara
     * `pembelian.manage`/`stock-opname.manage` (operasi stok/gudang
     * sehari-hari, bukan pengaturan admin tier seperti `branches.manage`).
     * Same conditional pattern as prior features: only created + attached
     * here on an already-seeded database; on a fresh install,
     * RolesAndPermissionsSeeder (updated alongside this migration)
     * creates and attaches it during `db:seed`.
     */
    public function up(): void
    {
        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'distributions.manage'],
                ['label' => 'Distribusi Stok', 'group' => 'Transaksi'],
            );

            Role::where('name', 'Admin')->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );

            // Manajer juga dapat -- operasi stok sehari-hari, pola sama
            // pembelian.manage/stock-opname.manage yang keduanya juga
            // diberikan ke Manajer (lihat RolesAndPermissionsSeeder).
            Role::where('name', 'Manajer')->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );
        }
    }

    public function down(): void
    {
        //
    }
};
