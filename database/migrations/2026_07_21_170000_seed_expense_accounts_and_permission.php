<?php

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap data for the "beban operasional" (operating expense)
     * feature: new CoA accounts + a new permission.
     *
     * Accounts are safe to insert unconditionally here (firstOrCreate
     * guards against ever re-running against a database that already has
     * them) because Account rows have no ordering dependency on anything
     * else — unlike FoundationSeeder's original 8 accounts, which are only
     * ever created via `db:seed` on a fresh install, these five are only
     * ever created via this migration, so both a fresh install (migrate
     * runs before seed) and an already-seeded dev database converge on the
     * same rows without a duplicate-key collision.
     *
     * The new permission is trickier: on a fresh install this migration
     * runs BEFORE RolesAndPermissionsSeeder creates the Admin/Manajer
     * roles, so there's nothing to attach it to yet — in that case we
     * deliberately do nothing here and let the (separately updated)
     * RolesAndPermissionsSeeder create + attach it during `db:seed`, same
     * as its other permissions. On an already-seeded database the roles
     * already exist at migration time, so we create the permission here
     * and attach it directly — this is the only path that needs to run at
     * all for an existing install, since nobody re-runs
     * RolesAndPermissionsSeeder against one.
     */
    public function up(): void
    {
        collect([
            ['code' => '2-2000', 'name' => 'Hutang Beban', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '5-3000', 'name' => 'Beban Listrik & Air', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5-3100', 'name' => 'Beban Sewa', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5-3200', 'name' => 'Beban Gaji', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5-3900', 'name' => 'Beban Lain-lain', 'type' => 'expense', 'normal_balance' => 'debit'],
        ])->each(fn (array $account) => Account::firstOrCreate(['code' => $account['code']], $account));

        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'beban.manage'],
                ['label' => 'Beban Operasional', 'group' => 'Transaksi'],
            );

            Role::whereIn('name', ['Admin', 'Manajer'])->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );
        }
    }

    /**
     * Deliberately does not delete the accounts/permission: by the time
     * anyone rolls this back, journal_lines may already reference these
     * accounts (accounts are never deleted once posted-to, per
     * PRINCIPLES.md's "accounts resolved by code" discipline), and roles
     * may already have real permission grants layered on top. Rolling back
     * schema-only migrations is safe; rolling back seeded reference data
     * that other rows may depend on is not.
     */
    public function down(): void
    {
        //
    }
};
