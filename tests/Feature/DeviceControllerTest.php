<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Halaman admin "Kelola Perangkat" -- route TERSEMBUNYI dari navigasi (lihat
 * routes/web.php) tapi tetap digerbangi permission:devices.manage seperti
 * halaman admin lain manapun; "tersembunyi" murni UX, bukan pengganti
 * otorisasi -- test di bawah membuktikan otorisasi tetap penuh berlaku.
 */
class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    private function roleWith(array $permissionKeys): Role
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);

        $permissions = collect($permissionKeys)->map(
            fn (string $key) => Permission::create(['key' => $key, 'label' => $key, 'group' => 'Test']),
        );

        $role->permissions()->attach($permissions->pluck('id'));

        return $role;
    }

    public function test_user_without_devices_manage_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $this->actingAs($user)->get('/pengaturan/perangkat')->assertForbidden();
    }

    public function test_admin_can_view_the_hidden_devices_page(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['devices.manage'])->id]);
        Device::create(['device_id' => 'android-id-1', 'status' => Device::STATUS_PENDING]);

        $response = $this->actingAs($admin)->get('/pengaturan/perangkat');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pengaturan/Perangkat/Index')
            ->where('devices.0.device_id', 'android-id-1')
        );
    }

    public function test_admin_can_approve_a_pending_device(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['devices.manage'])->id]);
        $device = Device::create(['device_id' => 'android-id-2', 'status' => Device::STATUS_PENDING]);

        $this->actingAs($admin)->put(route('devices.approve', $device->id))->assertRedirect();

        $device->refresh();
        $this->assertSame(Device::STATUS_APPROVED, $device->status);
        $this->assertSame($admin->id, $device->approved_by_user_id);
        $this->assertNotNull($device->approved_at);
    }

    public function test_admin_can_revoke_an_approved_device_and_its_active_tokens_are_deleted(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['devices.manage'])->id]);
        $cashier = User::factory()->create();
        $device = Device::create(['device_id' => 'android-id-3', 'status' => Device::STATUS_APPROVED]);

        $token = $cashier->createToken('mobile', ['sync:pull']);
        $token->accessToken->device_id = 'android-id-3';
        $token->accessToken->save();
        $tokenId = $token->accessToken->id;

        $this->actingAs($admin)->put(route('devices.revoke', $device->id))->assertRedirect();

        $device->refresh();
        $this->assertSame(Device::STATUS_REVOKED, $device->status);
        $this->assertSame($admin->id, $device->revoked_by_user_id);
        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    // (g) Admin bisa memperpanjang/mempersingkat/mematikan grace period
    // kapan saja lewat halaman Pengaturan yang sudah ada.
    public function test_admin_can_extend_and_disable_the_device_binding_grace_period(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['company-settings.manage'])->id]);

        $this->actingAs($admin)->put(route('pengaturan.device-binding-grace-period.update'), [
            'action' => 'extend',
            'days' => 30,
        ])->assertRedirect();

        $setting = CompanySetting::current();
        $this->assertTrue($setting->deviceBindingGracePeriodActive());
        $this->assertTrue($setting->device_binding_grace_period_ends_at->isAfter(now()->addDays(29)));

        $this->actingAs($admin)->put(route('pengaturan.device-binding-grace-period.update'), [
            'action' => 'disable',
        ])->assertRedirect();

        $this->assertFalse(CompanySetting::current()->deviceBindingGracePeriodActive());
        $this->assertNull(CompanySetting::current()->device_binding_grace_period_ends_at);
    }
}
