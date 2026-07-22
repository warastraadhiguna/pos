<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    // Registrasi dinonaktifkan sementara (lihat routes/auth.php) — akun
    // dibuat lewat seeder/tinker untuk sekarang. Skip, bukan hapus, supaya
    // gampang dinyalakan lagi begitu route-nya di-uncomment.

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->markTestSkipped('Registrasi dinonaktifkan sementara — lihat routes/auth.php.');

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $this->markTestSkipped('Registrasi dinonaktifkan sementara — lihat routes/auth.php.');

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
