<?php

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap data for the Kas/Bank separation feature: a "Kas & Bank"
     * group header account, one starter Bank account, and a new
     * permission for the treasury (transfer + manage bank accounts) UI.
     *
     * "Which accounts count as cash/bank" is answered the way a real
     * chart of accounts would -- via parent/child grouping (the
     * previously-unused `accounts.parent_id`/`children()` relation), not a
     * bespoke boolean flag. The header itself (code "1-1") is never
     * posted to by anything; it exists purely to answer "give me every
     * account under Kas & Bank" via `Account::where('parent_id', ...)`
     * (see CashAccountService).
     *
     * Kas (1-1000) already exists from FoundationSeeder on any database
     * that's been seeded before -- this migration backfills its
     * parent_id to point at the new header. On a FRESH install this
     * migration runs BEFORE FoundationSeeder (migrate always precedes
     * seed), so the 1-1000 row doesn't exist yet at this point; the
     * `update()` below is then a no-op, and FoundationSeeder (updated
     * alongside this migration) sets parent_id correctly itself when it
     * creates that row, since the header will already exist by then.
     *
     * The permission follows the exact same "only attach if roles already
     * exist" guard used for `beban.manage` (2026_07_21_170000): on a
     * fresh install roles don't exist yet at migration time, so
     * RolesAndPermissionsSeeder (updated alongside this migration) creates
     * and attaches it; on an already-seeded database, this migration
     * creates + attaches it directly.
     */
    public function up(): void
    {
        $header = Account::firstOrCreate(
            ['code' => '1-1'],
            ['name' => 'Kas & Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true],
        );

        Account::firstOrCreate(
            ['code' => '1-1100'],
            ['name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $header->id, 'is_active' => true],
        );

        Account::where('code', '1-1000')->update(['parent_id' => $header->id]);

        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'kas-bank.manage'],
                ['label' => 'Kas & Bank', 'group' => 'Transaksi'],
            );

            Role::whereIn('name', ['Admin', 'Manajer'])->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );
        }
    }

    /**
     * Deliberately does not delete anything -- by the time anyone rolls
     * this back, journal_lines may already reference 1-1100, and Kas's
     * parent_id may already be relied on elsewhere. Same reasoning as
     * 2026_07_21_170000's down().
     */
    public function down(): void
    {
        //
    }
};
