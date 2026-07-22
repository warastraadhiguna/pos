<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed the fixed set of permissions and the three default roles.
     *
     * Deliberately does NOT touch any user or auto-assign a role to
     * anyone — an earlier version backfilled every roleless user to
     * Admin, which silently handed full admin access to whichever
     * account happened to exist without a role (including a seeded test
     * account). Role assignment is now always explicit: the real admin
     * account is created separately via `php artisan admin:create`.
     */
    public function run(): void
    {
        $permissions = collect([
            ['key' => 'kasir.access', 'label' => 'Kasir (POS)', 'group' => 'Transaksi'],
            ['key' => 'penjualan.view', 'label' => 'Riwayat Penjualan', 'group' => 'Transaksi'],
            ['key' => 'pembelian.manage', 'label' => 'Pembelian', 'group' => 'Transaksi'],
            ['key' => 'beban.manage', 'label' => 'Beban Operasional', 'group' => 'Transaksi'],
            ['key' => 'kas-bank.manage', 'label' => 'Kas & Bank', 'group' => 'Transaksi'],
            ['key' => 'modal.manage', 'label' => 'Modal & Prive', 'group' => 'Transaksi'],
            ['key' => 'aset.manage', 'label' => 'Aset Tetap', 'group' => 'Transaksi'],
            ['key' => 'coa.manage', 'label' => 'Chart of Accounts', 'group' => 'Transaksi'],
            ['key' => 'stock-opname.manage', 'label' => 'Stock Opname', 'group' => 'Transaksi'],
            ['key' => 'master-data.manage', 'label' => 'Master Data', 'group' => 'Master Data'],
            ['key' => 'laporan.view', 'label' => 'Laporan', 'group' => 'Laporan'],
            ['key' => 'pengguna.manage', 'label' => 'Kelola Pengguna', 'group' => 'Pengaturan'],
            ['key' => 'roles.manage', 'label' => 'Kelola Role & Izin', 'group' => 'Pengaturan'],
            ['key' => 'company-settings.manage', 'label' => 'Pengaturan Pajak (PPN)', 'group' => 'Pengaturan'],
        ])->mapWithKeys(fn (array $permission) => [$permission['key'] => Permission::create($permission)]);

        $admin = Role::create(['name' => 'Admin']);
        $admin->permissions()->attach($permissions->pluck('id'));

        $manajer = Role::create(['name' => 'Manajer']);
        $manajer->permissions()->attach(
            $permissions->except(['pengguna.manage', 'roles.manage', 'company-settings.manage', 'modal.manage', 'coa.manage'])->pluck('id'),
        );

        $kasir = Role::create(['name' => 'Kasir']);
        $kasir->permissions()->attach(
            $permissions->only(['kasir.access', 'penjualan.view'])->pluck('id'),
        );
    }
}
