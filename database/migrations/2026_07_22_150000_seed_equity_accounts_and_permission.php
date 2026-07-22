<?php

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap data for the Modal/Prive (owner's equity) feature: two new
     * CoA accounts and a new Admin-only permission.
     *
     * Prive (3-2000) is deliberately seeded with normal_balance='credit'
     * even though every transaction debits it -- this is the standard
     * double-entry technique for a CONTRA account (same idea as
     * Accumulated Depreciation being credit-normal despite living among
     * debit-normal asset accounts). It means
     * FinancialReportService::accountsForType()'s existing "normal
     * positive balance" formula (credit − debit for credit-normal
     * accounts) naturally computes Prive's balance as NEGATIVE, so the
     * already-existing sumBalances() call in balanceSheet() correctly
     * nets Modal + Prive into (Modal − withdrawals) with ZERO changes to
     * that report code. Getting this flag right at seed time is what
     * makes the rest of the feature require no special-case "subtract
     * contra accounts" logic anywhere.
     *
     * Same idempotent/conditional-permission pattern as the two prior
     * features (beban.manage, kas-bank.manage): accounts are always safe
     * to firstOrCreate; the permission is only created+attached here if
     * roles already exist (an already-seeded database) — on a fresh
     * install, RolesAndPermissionsSeeder (updated alongside this
     * migration) creates and attaches it during `db:seed`, which always
     * runs after migrations.
     */
    public function up(): void
    {
        Account::firstOrCreate(
            ['code' => '3-1000'],
            ['name' => 'Modal Pemilik', 'type' => 'equity', 'normal_balance' => 'credit', 'is_active' => true],
        );

        Account::firstOrCreate(
            ['code' => '3-2000'],
            ['name' => 'Prive (Pengambilan Pribadi)', 'type' => 'equity', 'normal_balance' => 'credit', 'is_active' => true],
        );

        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'modal.manage'],
                ['label' => 'Modal & Prive', 'group' => 'Transaksi'],
            );

            // Admin-only (bukan Manajer) -- setoran/pengambilan modal
            // adalah keputusan level pemilik usaha, lebih personal/sensitif
            // dibanding beban operasional atau transfer kas-bank rutin.
            Role::where('name', 'Admin')->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );
        }
    }

    /**
     * Deliberately does not delete anything -- same reasoning as the
     * prior two features' seed migrations (journal_lines may already
     * reference these accounts by the time anyone rolls this back).
     */
    public function down(): void
    {
        //
    }
};
