<?php

namespace Tests\Feature\Api;

use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Device Binding -- sisi server dari rancangan yang disetujui. Kasus tepi
 * (c)/(d)/(e)/(g)/(h) dari checklist verifikasi WAJIB; kasus (a)/(b) (grace
 * offline 7 hari) murni logika sisi mobile (domain layer, pos_mobile) --
 * lihat test Dart untuk itu, tidak direplikasi di sini.
 */
class DeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    private function createUser(): User
    {
        return User::factory()->create(['password' => bcrypt('secret1234')]);
    }

    private function login(User $user, string $deviceId, ?string $deviceLabel = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_id' => $deviceId,
            'device_label' => $deviceLabel,
        ]);
    }

    // (e) Device baru login SETELAH grace period (default FoundationSeeder:
    // device_binding_grace_period_ends_at masih NULL/mati) -> masuk pending,
    // tidak dapat token.
    public function test_new_device_without_active_grace_period_is_registered_as_pending_and_gets_no_token(): void
    {
        $user = $this->createUser();

        $response = $this->login($user, 'android-id-new-device-1');

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'device_pending');
        $this->assertNull($response->json('token'));

        $device = Device::where('device_id', 'android-id-new-device-1')->first();
        $this->assertNotNull($device);
        $this->assertSame(Device::STATUS_PENDING, $device->status);
    }

    // (d) & (f) Device baru (termasuk device existing yang baru pertama kali
    // mengirim device_id lewat APK baru) login SELAMA grace period aktif ->
    // auto-approved, langsung dapat token, TANPA aksi admin apa pun.
    public function test_new_device_during_active_grace_period_is_auto_approved_without_admin_action(): void
    {
        CompanySetting::current()->update([
            'device_binding_grace_period_ends_at' => Carbon::now()->addDays(14),
        ]);
        $user = $this->createUser();

        $response = $this->login($user, 'android-id-existing-tablet', 'Samsung A12');

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));

        $device = Device::where('device_id', 'android-id-existing-tablet')->first();
        $this->assertSame(Device::STATUS_APPROVED, $device->status);
        $this->assertNull($device->approved_by_user_id, 'auto-approval lewat grace period tidak boleh tercatat sebagai persetujuan admin manual');
        $this->assertNotNull($device->approved_at);
    }

    public function test_grace_period_that_has_already_expired_does_not_auto_approve(): void
    {
        CompanySetting::current()->update([
            'device_binding_grace_period_ends_at' => Carbon::now()->subDay(),
        ]);
        $user = $this->createUser();

        $response = $this->login($user, 'android-id-late-device');

        $response->assertStatus(403)->assertJsonPath('error_code', 'device_pending');
    }

    // (h) Login WAJIB dua-duanya: kredensial valid DAN device approved --
    // salah satu saja tidak cukup.
    public function test_login_requires_both_valid_credentials_and_an_approved_device(): void
    {
        $user = $this->createUser();

        // Kredensial salah, device_id apa pun -- tetap ditolak di validasi
        // kredensial (tidak pernah sampai mengecek device).
        $wrongPassword = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_id' => 'android-id-x',
        ]);
        $wrongPassword->assertStatus(422);
        $this->assertNull(Device::where('device_id', 'android-id-x')->first(), 'device tidak boleh terdaftar dari percobaan dengan kredensial salah');

        // Kredensial benar, device belum approved -- tetap ditolak.
        $pendingDevice = $this->login($user, 'android-id-y');
        $pendingDevice->assertStatus(403);

        // Device di atas disetujui admin -> sekarang kredensial benar +
        // device approved, dua-duanya terpenuhi -> berhasil.
        Device::where('device_id', 'android-id-y')->first()->update(['status' => Device::STATUS_APPROVED]);
        $approved = $this->login($user, 'android-id-y');
        $approved->assertOk();
    }

    // (c) Revoke device yang SEDANG ONLINE -> instan: token yang tertaut
    // langsung dihapus, request berikutnya 401 tanpa menunggu cek berkala.
    public function test_revoking_an_online_device_immediately_deletes_its_tokens(): void
    {
        CompanySetting::current()->update(['device_binding_grace_period_ends_at' => Carbon::now()->addDay()]);
        $user = $this->createUser();

        $loginResponse = $this->login($user, 'android-id-revoke-me');
        $loginResponse->assertOk();
        $token = $loginResponse->json('token');

        $tokenId = explode('|', $token, 2)[0];
        $this->assertNotNull(PersonalAccessToken::find($tokenId));

        $device = Device::where('device_id', 'android-id-revoke-me')->first();
        app(\App\Services\DeviceService::class)->revoke($device, User::factory()->create());

        $this->assertNull(PersonalAccessToken::find($tokenId), 'token milik device yang dicabut harus langsung terhapus');
        $this->assertSame(Device::STATUS_REVOKED, $device->fresh()->status);
    }

    public function test_login_from_a_revoked_device_is_rejected_even_with_correct_credentials(): void
    {
        CompanySetting::current()->update(['device_binding_grace_period_ends_at' => Carbon::now()->addDay()]);
        $user = $this->createUser();
        $this->login($user, 'android-id-z')->assertOk();
        Device::where('device_id', 'android-id-z')->first()->update(['status' => Device::STATUS_REVOKED]);

        $response = $this->login($user, 'android-id-z');

        $response->assertStatus(403)->assertJsonPath('error_code', 'device_revoked');
    }

    // Client lama (APK sebelum Device Binding, tidak pernah mengirim
    // device_id sama sekali) -- login TETAP berjalan seperti sebelum fitur
    // ini ada. Poin 8 rancangan: fitur ini tidak boleh mendadak memblokir
    // siapa pun yang belum sempat update APK-nya sama sekali.
    public function test_login_without_device_id_at_all_bypasses_device_binding_entirely(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(0, Device::count());
    }

    public function test_device_status_endpoint_reports_current_status_and_updates_last_seen(): void
    {
        CompanySetting::current()->update(['device_binding_grace_period_ends_at' => Carbon::now()->addDay()]);
        $user = $this->createUser();
        $token = $this->login($user, 'android-id-status-check')->json('token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/device/status');

        $response->assertOk();
        $response->assertJsonPath('status', 'approved');
        $this->assertNotNull($response->json('checked_at'));

        $device = Device::where('device_id', 'android-id-status-check')->first();
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_a_token_issued_before_device_binding_lacks_the_device_status_ability_and_is_rejected(): void
    {
        $user = $this->createUser();
        // Token lama pra-fitur ini -- diterbitkan tanpa ability device:status
        // (createToken di sini meniru abilities yang lebih pendek yang
        // pernah dipakai sebelum fitur ini ada).
        $plainTextToken = $user->createToken('mobile', ['sync:pull', 'sync:push', 'sync:status'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->getJson('/api/v1/device/status');

        $response->assertStatus(403);
    }
}
