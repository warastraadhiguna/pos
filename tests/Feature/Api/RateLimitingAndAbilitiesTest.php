<?php

namespace Tests\Feature\Api;

use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class RateLimitingAndAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        CompanySetting::create(['ppn_active' => true]);
    }

    // --- Rate limiting ---

    public function test_login_is_throttled_after_five_attempts_per_minute(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
            // Salah password tapi belum kena limit — tetap 422 (validasi
            // kredensial), bukan 429.
            $response->assertStatus(422);
        }

        // Percobaan ke-6 dalam jendela yang sama (email+IP) harus diblokir
        // oleh limiter, terlepas dari benar/salahnya password kali ini.
        $sixth = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ]);

        $sixth->assertStatus(429);
    }

    public function test_login_throttle_is_scoped_per_email_not_shared_globally(): void
    {
        $userA = User::factory()->create(['password' => bcrypt('secret1234')]);
        $userB = User::factory()->create(['password' => bcrypt('secret1234')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', [
                'email' => $userA->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }
        // userA sekarang throttled.
        $this->postJson('/api/v1/login', [
            'email' => $userA->email,
            'password' => 'secret1234',
        ])->assertStatus(429);

        // userB, email berbeda, key limiter berbeda — belum tersentuh.
        $this->postJson('/api/v1/login', [
            'email' => $userB->email,
            'password' => 'secret1234',
        ])->assertOk();
    }

    public function test_mobile_api_throttle_blocks_a_single_token_after_100_requests_per_minute(): void
    {
        $token = $this->loginAndGetToken();
        $this->withHeader('Authorization', "Bearer {$token}");

        for ($i = 0; $i < 100; $i++) {
            $this->getJson('/api/v1/products')->assertOk();
        }

        // Request ke-101 dalam menit yang sama, token yang sama, harus kena limit.
        $this->getJson('/api/v1/products')->assertStatus(429);
    }

    // --- Token ability scoping ---

    public function test_freshly_issued_token_can_access_all_three_mobile_endpoint_groups(): void
    {
        $token = $this->loginAndGetToken();
        $this->withHeader('Authorization', "Bearer {$token}");

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/product-categories')->assertOk();

        $product = Product::create(['name' => 'Produk Uji', 'sell_price' => 5000]);
        $localUuid = (string) Str::uuid();
        $this->postJson('/api/v1/sales', [
            'local_uuid' => $localUuid,
            'date' => '2026-07-11',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $this->getJson("/api/v1/sales/{$localUuid}")->assertOk();
    }

    public function test_token_scoped_to_pull_only_is_rejected_from_pushing_a_sale(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        $token = $user->createToken('scoped-test', ['sync:pull'])->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");

        // Boleh tarik data...
        $this->getJson('/api/v1/products')->assertOk();

        // ...tapi tidak boleh kirim penjualan — ability sync:push tidak ada.
        $product = Product::create(['name' => 'Produk Uji', 'sell_price' => 5000]);
        $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-11',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ])->assertStatus(403);
    }

    public function test_token_scoped_to_push_only_is_rejected_from_pulling_master_data(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        $token = $user->createToken('scoped-test', ['sync:push'])->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");

        $this->getJson('/api/v1/products')->assertStatus(403);
    }

    public function test_legacy_token_without_scoped_abilities_still_works_on_every_endpoint(): void
    {
        // Simulasi token yang diterbitkan SEBELUM perubahan ini (default
        // Sanctum, abilities ['*']) — HP kasir yang sudah login sebelum
        // deploy tidak boleh mendadak gagal sync.
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        $legacyToken = $user->createToken('legacy-device')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$legacyToken}");

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/product-categories')->assertOk();

        $product = Product::create(['name' => 'Produk Uji', 'sell_price' => 5000]);
        $localUuid = (string) Str::uuid();
        $this->postJson('/api/v1/sales', [
            'local_uuid' => $localUuid,
            'date' => '2026-07-11',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $this->getJson("/api/v1/sales/{$localUuid}")->assertOk();
    }

    // --- Alur normal end-to-end (login -> tarik -> kirim) tetap jalan ---

    public function test_normal_flow_login_then_pull_then_push_succeeds(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_name' => 'Kasir HP Uji',
        ])->assertOk();

        $token = $loginResponse->json('token');
        $this->withHeader('Authorization', "Bearer {$token}");

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/product-categories')->assertOk();

        $product = Product::create(['name' => 'Produk Uji', 'sell_price' => 5000]);
        $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-11',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $this->postJson('/api/v1/logout')->assertOk();

        // Sanctum's RequestGuard caches the resolved user for the guard's
        // lifetime — must be forgotten to force re-resolution against the
        // now-deleted token (same reasoning as AuthControllerTest).
        Auth::forgetGuards();
        $this->getJson('/api/v1/products')->assertStatus(401);
    }

    private function loginAndGetToken(): string
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);

        return $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ])->json('token');
    }
}
