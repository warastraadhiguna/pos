<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap permission `penjualan.void` ("Batalkan Transaksi") --
     * Admin-only (bukan Manajer/Kasir), sensitivitas sama seperti
     * `modal.manage`/`coa.manage` karena void menyentuh jurnal & stok
     * langsung. Pola sama migrasi seed-permission lain: hanya jalan di
     * database yang SUDAH pernah di-seed -- instalasi fresh dilayani
     * langsung oleh RolesAndPermissionsSeeder (diperbarui berbarengan).
     */
    public function up(): void
    {
        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'penjualan.void'],
                ['label' => 'Batalkan Transaksi', 'group' => 'Transaksi'],
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
