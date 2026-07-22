<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_creates_a_new_admin_with_valid_password(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'owner@wanpos.test')
            ->expectsQuestion('Nama admin', 'Pemilik Toko')
            ->expectsQuestion('Password admin (ketikan tersembunyi, minimal 10 karakter)', 'RahasiaKuat99')
            ->expectsQuestion('Ketik ulang password untuk konfirmasi', 'RahasiaKuat99')
            ->assertExitCode(0);

        $user = User::where('email', 'owner@wanpos.test')->firstOrFail();
        $this->assertSame('Pemilik Toko', $user->name);
        $this->assertSame('Admin', $user->role->name);
        $this->assertTrue(Hash::check('RahasiaKuat99', $user->password));
    }

    public function test_rejects_password_shorter_than_ten_characters(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'owner@wanpos.test')
            ->expectsQuestion('Nama admin', 'Pemilik Toko')
            ->expectsQuestion('Password admin (ketikan tersembunyi, minimal 10 karakter)', 'Pendek1')
            ->assertExitCode(1);

        $this->assertSame(0, User::count(), 'Tidak boleh ada user tersimpan kalau password ditolak.');
    }

    public function test_rejects_password_without_numbers(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'owner@wanpos.test')
            ->expectsQuestion('Nama admin', 'Pemilik Toko')
            ->expectsQuestion('Password admin (ketikan tersembunyi, minimal 10 karakter)', 'HanyaHurufSaja')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_rejects_a_common_password_that_technically_passes_the_length_and_character_rules(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'owner@wanpos.test')
            ->expectsQuestion('Nama admin', 'Pemilik Toko')
            ->expectsQuestion('Password admin (ketikan tersembunyi, minimal 10 karakter)', 'password123')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_rejects_mismatched_password_confirmation(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'owner@wanpos.test')
            ->expectsQuestion('Nama admin', 'Pemilik Toko')
            ->expectsQuestion('Password admin (ketikan tersembunyi, minimal 10 karakter)', 'RahasiaKuat99')
            ->expectsQuestion('Ketik ulang password untuk konfirmasi', 'RahasiaBeda99')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_rejects_invalid_email_format(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'bukan-email')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_promotes_an_existing_user_to_admin_when_confirmed(): void
    {
        $kasirRole = Role::where('name', 'Kasir')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'kasir@wanpos.test',
            'role_id' => $kasirRole->id,
        ]);

        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'kasir@wanpos.test')
            ->expectsConfirmation('Jadikan akun ini Admin?', 'yes')
            ->expectsConfirmation('Reset password akun ini juga?', 'no')
            ->assertExitCode(0);

        $this->assertSame('Admin', $existing->fresh()->role->name);
    }

    public function test_declining_to_promote_an_existing_user_makes_no_changes(): void
    {
        $kasirRole = Role::where('name', 'Kasir')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'kasir@wanpos.test',
            'role_id' => $kasirRole->id,
        ]);

        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'kasir@wanpos.test')
            ->expectsConfirmation('Jadikan akun ini Admin?', 'no')
            ->assertExitCode(0);

        $this->assertSame('Kasir', $existing->fresh()->role->name);
    }

    public function test_running_again_on_an_existing_admin_is_a_no_op(): void
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'owner@wanpos.test',
            'role_id' => $adminRole->id,
        ]);

        $this->artisan('admin:create')
            ->expectsQuestion('Email admin', 'owner@wanpos.test')
            ->assertExitCode(0);

        $this->assertSame(1, User::count(), 'Tidak boleh membuat duplikat.');
        $this->assertSame($existing->id, User::firstOrFail()->id);
    }

    public function test_fails_clearly_when_admin_role_does_not_exist_yet(): void
    {
        Role::where('name', 'Admin')->delete();

        $this->artisan('admin:create')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }
}
