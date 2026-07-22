<?php

namespace Tests\Feature\Api;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_device_name_uses_it_as_the_token_name(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        $token = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_name' => 'Kasir HP Budi',
        ])->assertOk()->json('token');

        $tokenId = explode('|', $token, 2)[0];
        $this->assertSame('Kasir HP Budi', PersonalAccessToken::find($tokenId)->name);
    }

    public function test_login_without_device_name_falls_back_to_a_default_token_name(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        $token = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ])->assertOk()->json('token');

        $tokenId = explode('|', $token, 2)[0];
        $this->assertSame('mobile', PersonalAccessToken::find($tokenId)->name);
    }

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_request_to_protected_endpoint_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(401);
    }

    public function test_token_from_login_can_access_a_protected_endpoint(): void
    {
        // GET /api/v1/products reads the company_settings singleton (PPN
        // switch) into its response meta — needs a row to exist, same as
        // it always would in a real, seeded database.
        CompanySetting::create(['ppn_active' => true]);

        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        $token = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/products')
            ->assertOk();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        $token = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertOk();

        // Sanctum's RequestGuard caches the resolved user for its lifetime;
        // within a single test method that lifetime spans every simulated
        // request, so it must be forgotten to force re-resolution against
        // the (now-deleted) token — this never happens across real requests
        // in production, each of which boots a fresh guard.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/products')
            ->assertStatus(401);
    }
}
