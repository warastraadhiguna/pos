<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateDeveloperCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_creates_a_new_developer_with_valid_password(): void
    {
        $this->artisan('developer:create')
            ->expectsQuestion('Email developer', 'dev@wanpos.test')
            ->expectsQuestion('Nama developer', 'Developer Utama')
            ->expectsQuestion('Password developer (ketikan tersembunyi, minimal 10 karakter)', 'RahasiaKuat99')
            ->expectsQuestion('Ketik ulang password untuk konfirmasi', 'RahasiaKuat99')
            ->assertExitCode(0);

        $user = User::where('email', 'dev@wanpos.test')->firstOrFail();
        $this->assertSame('Developer Utama', $user->name);
        $this->assertSame('Developer', $user->role->name);
        $this->assertTrue($user->role->is_developer);
        $this->assertTrue(Hash::check('RahasiaKuat99', $user->password));
    }

    public function test_new_developer_account_has_an_empty_permission_pivot_and_still_bypasses_everything(): void
    {
        $this->artisan('developer:create')
            ->expectsQuestion('Email developer', 'dev@wanpos.test')
            ->expectsQuestion('Nama developer', 'Developer Utama')
            ->expectsQuestion('Password developer (ketikan tersembunyi, minimal 10 karakter)', 'RahasiaKuat99')
            ->expectsQuestion('Ketik ulang password untuk konfirmasi', 'RahasiaKuat99')
            ->assertExitCode(0);

        $user = User::where('email', 'dev@wanpos.test')->firstOrFail();
        $this->assertSame(0, $user->role->permissions()->count());
        $this->assertTrue($user->hasPermission('devices.manage'));
    }

    public function test_rejects_password_shorter_than_ten_characters(): void
    {
        $this->artisan('developer:create')
            ->expectsQuestion('Email developer', 'dev@wanpos.test')
            ->expectsQuestion('Nama developer', 'Developer Utama')
            ->expectsQuestion('Password developer (ketikan tersembunyi, minimal 10 karakter)', 'Pendek1')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_rejects_invalid_email_format(): void
    {
        $this->artisan('developer:create')
            ->expectsQuestion('Email developer', 'bukan-email')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_promotes_an_existing_user_to_developer_when_confirmed(): void
    {
        $kasirRole = Role::where('name', 'Kasir')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'kasir@wanpos.test',
            'role_id' => $kasirRole->id,
        ]);

        $this->artisan('developer:create')
            ->expectsQuestion('Email developer', 'kasir@wanpos.test')
            ->expectsConfirmation('Jadikan akun ini Developer?', 'yes')
            ->expectsConfirmation('Reset password akun ini juga?', 'no')
            ->assertExitCode(0);

        $this->assertSame('Developer', $existing->fresh()->role->name);
    }

    public function test_declining_to_promote_an_existing_user_makes_no_changes(): void
    {
        $kasirRole = Role::where('name', 'Kasir')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'kasir@wanpos.test',
            'role_id' => $kasirRole->id,
        ]);

        $this->artisan('developer:create')
            ->expectsQuestion('Email developer', 'kasir@wanpos.test')
            ->expectsConfirmation('Jadikan akun ini Developer?', 'no')
            ->assertExitCode(0);

        $this->assertSame('Kasir', $existing->fresh()->role->name);
    }

    public function test_running_again_on_an_existing_developer_is_a_no_op(): void
    {
        $developerRole = Role::where('name', 'Developer')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'dev@wanpos.test',
            'role_id' => $developerRole->id,
        ]);

        $this->artisan('developer:create')
            ->expectsQuestion('Email developer', 'dev@wanpos.test')
            ->assertExitCode(0);

        $this->assertSame(1, User::count(), 'Tidak boleh membuat duplikat.');
        $this->assertSame($existing->id, User::firstOrFail()->id);
    }

    public function test_fails_clearly_when_developer_role_does_not_exist_yet(): void
    {
        Role::where('name', 'Developer')->delete();

        $this->artisan('developer:create')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }
}
