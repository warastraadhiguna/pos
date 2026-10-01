<?php

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap data for the "konsinyasi" (consignment) feature: a new CoA
     * account + a new permission. Pola PERSIS
     * 2026_07_21_170000_seed_expense_accounts_and_permission.php.
     *
     * Account aman diinsert tanpa syarat di sini (firstOrCreate menjaga
     * dari re-run) -- tidak ada dependensi urutan ke apa pun lain.
     *
     * Permission: pada instalasi BARU, migrasi ini jalan SEBELUM
     * RolesAndPermissionsSeeder membuat role Admin/Manajer, jadi tidak ada
     * apa pun untuk di-attach di sini -- sengaja tidak melakukan apa-apa,
     * biarkan seeder (sudah diperbarui juga) yang membuat+attach saat
     * `db:seed`. Pada database yang SUDAH diseed, role sudah ada saat
     * migrasi ini jalan, jadi permission dibuat+attach langsung di sini --
     * ini satu-satunya jalur yang perlu jalan untuk instalasi yang sudah
     * ada, karena tidak ada yang menjalankan ulang RolesAndPermissionsSeeder
     * terhadapnya.
     */
    public function up(): void
    {
        Account::firstOrCreate(
            ['code' => '2-3000'],
            ['name' => 'Hutang Konsinyasi', 'type' => 'liability', 'normal_balance' => 'credit'],
        );

        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'konsinyasi.manage'],
                ['label' => 'Konsinyasi', 'group' => 'Transaksi'],
            );

            Role::whereIn('name', ['Admin', 'Manajer'])->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );
        }
    }

    /**
     * Deliberately does not delete the account/permission: by the time
     * anyone rolls this back, journal_lines/consignment_accruals may
     * already reference this account, and roles may already have real
     * permission grants layered on top. Same reasoning as the migration
     * this one mirrors.
     */
    public function down(): void
    {
        //
    }
};
