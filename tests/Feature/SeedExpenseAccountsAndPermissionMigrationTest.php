<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedExpenseAccountsAndPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_all_expected_accounts(): void
    {
        $codes = Account::whereIn('code', ['2-2000', '5-3000', '5-3100', '5-3200', '5-3900'])->pluck('code');
        $this->assertCount(5, $codes);

        $hutangBeban = Account::where('code', '2-2000')->firstOrFail();
        $this->assertSame('liability', $hutangBeban->type);
        $this->assertSame('credit', $hutangBeban->normal_balance);
    }

    public function test_migration_is_safe_to_run_again_without_duplicating_accounts(): void
    {
        $this->runMigrationAgain();

        $this->assertSame(1, Account::where('code', '2-2000')->count());
        $this->assertSame(1, Account::where('code', '5-3000')->count());
    }

    /**
     * Simulasikan database yang sudah pernah di-seed SEBELUM fitur ini ada:
     * role sudah ada, tapi permission 'beban.manage' belum -- karena
     * migrasi ini pada dasarnya berjalan pertama kali SEBELUM roles ada di
     * database test (RefreshDatabase memigrasikan sebelum test manapun
     * men-seed role). Menjalankan migrasi lagi di sini harus membuat +
     * melekatkan permission-nya, tepat seperti yang terjadi di database
     * dev yang sudah berjalan.
     */
    public function test_migration_attaches_the_permission_to_existing_roles_when_roles_already_exist(): void
    {
        $admin = Role::create(['name' => 'Admin']);
        $manajer = Role::create(['name' => 'Manajer']);
        $kasir = Role::create(['name' => 'Kasir']);

        $this->assertNull(Permission::where('key', 'beban.manage')->first());

        $this->runMigrationAgain();

        $permission = Permission::where('key', 'beban.manage')->first();
        $this->assertNotNull($permission);
        $this->assertTrue($admin->fresh()->permissions->contains($permission));
        $this->assertTrue($manajer->fresh()->permissions->contains($permission));
        $this->assertFalse($kasir->fresh()->permissions->contains($permission));
    }

    private function runMigrationAgain(): void
    {
        $migration = require database_path('migrations/2026_07_21_170000_seed_expense_accounts_and_permission.php');
        $migration->up();
    }
}
