<?php

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bootstrap data for the Fixed Assets / Depreciation feature: four new
     * CoA accounts and a new permission.
     *
     * Akumulasi Penyusutan (1-2900) is a CONTRA-asset account, seeded with
     * normal_balance='debit' -- the SAME as the dominant convention for
     * type=asset (Kas, Persediaan, Aset Tetap are all debit-normal), NOT
     * the opposite. Its actual journal entries always CREDIT it (Dr Beban
     * Penyusutan / Cr Akumulasi Penyusutan), so under a debit-normal
     * formula (debit − credit) it naturally computes as NEGATIVE, letting
     * FinancialReportService's existing sumBalances() net it against Aset
     * Tetap into a correct nilai buku with zero code changes. Same
     * technique as Prive (contra-equity, see 2026_07_22_150000).
     *
     * Beban Penyusutan (5-4000) sits deliberately OUTSIDE both the COGS
     * prefix list (['5-1','5-2']) and the admin-manageable operational
     * range (5-3xxx) -- it lands in the "operational" bucket of Laba Rugi
     * automatically (no changes to FinancialReportService's grouping),
     * while being added to ExpenseService::RESERVED_SYSTEM_EXPENSE_CODES
     * (in the same migration-adjacent code change) so nobody can select
     * it for a manual "Catat Beban" entry -- it may only ever be posted by
     * DepreciationService.
     *
     * Hutang Lain-lain (2-9000) is a deliberately GENERIC "other payables"
     * liability account -- distinct from Hutang Usaha (2-1000, trade
     * payables for merchandise) and Hutang Beban (2-2000, expense-specific)
     * so a credit-purchased fixed asset doesn't misclassify into either.
     * The "900" suffix mirrors the existing catch-all convention (5-3900
     * Beban Lain-lain).
     *
     * Same idempotent/conditional-permission pattern as the three prior
     * features: accounts are always safe to firstOrCreate; the permission
     * is only created+attached here if roles already exist (an
     * already-seeded database) -- on a fresh install, RolesAndPermissionsSeeder
     * (updated alongside this migration) creates and attaches it during
     * `db:seed`, which always runs after migrations.
     */
    public function up(): void
    {
        collect([
            ['code' => '1-2000', 'name' => 'Aset Tetap', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1-2900', 'name' => 'Akumulasi Penyusutan', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2-9000', 'name' => 'Hutang Lain-lain', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '5-4000', 'name' => 'Beban Penyusutan', 'type' => 'expense', 'normal_balance' => 'debit'],
        ])->each(fn (array $account) => Account::firstOrCreate(['code' => $account['code']], $account));

        if (Role::query()->exists()) {
            $permission = Permission::firstOrCreate(
                ['key' => 'aset.manage'],
                ['label' => 'Aset Tetap', 'group' => 'Transaksi'],
            );

            Role::whereIn('name', ['Admin', 'Manajer'])->get()->each(
                fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]),
            );
        }
    }

    /**
     * Deliberately does not delete anything -- same reasoning as the
     * prior three features' seed migrations.
     */
    public function down(): void
    {
        //
    }
};
