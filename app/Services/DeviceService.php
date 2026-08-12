<?php

namespace App\Services;

use App\Exceptions\DevicePendingException;
use App\Exceptions\DeviceRevokedException;
use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Device Binding -- lihat rancangan yang disetujui. Satu tempat yang
 * memutuskan apakah sebuah device_id (Android ID, dibaca & dikirim oleh
 * mobile client saat login) boleh lanjut mendapat token Sanctum atau tidak.
 * Api\AuthController::login() memanggil registerOrCheckLoginDevice() SETELAH
 * kredensial user tervalidasi (bukan sebelumnya) -- supaya endpoint ini
 * tidak jadi celah pendaftaran device anonim (mis. spam) tanpa kredensial
 * valid sama sekali.
 */
class DeviceService
{
    /**
     * @throws DevicePendingException kalau device belum (atau belum lagi) disetujui.
     * @throws DeviceRevokedException kalau device sudah dicabut.
     */
    public function registerOrCheckLoginDevice(string $deviceId, ?string $label, User $user): Device
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            $device = $this->registerNewDevice($deviceId, $label, $user);
        }

        if ($device->status === Device::STATUS_REVOKED) {
            throw new DeviceRevokedException($device);
        }

        if ($device->status === Device::STATUS_PENDING) {
            throw new DevicePendingException($device);
        }

        $device->update(['last_seen_at' => Carbon::now()]);

        return $device;
    }

    /**
     * Device_id baru -- auto-approved kalau grace period masih aktif
     * (jawaban untuk "jangan blokir device existing", lihat
     * CompanySetting::deviceBindingGracePeriodActive()), kalau tidak masuk
     * pending seperti alur normal jangka panjang. `approved_by_user_id`
     * sengaja TETAP null untuk auto-approval lewat grace period --
     * membedakannya di audit trail dari approval manual asli oleh admin.
     */
    private function registerNewDevice(string $deviceId, ?string $label, User $user): Device
    {
        $autoApprove = CompanySetting::current()->deviceBindingGracePeriodActive();

        return Device::create([
            'device_id' => $deviceId,
            'label' => $label,
            'status' => $autoApprove ? Device::STATUS_APPROVED : Device::STATUS_PENDING,
            'registered_by_user_id' => $user->id,
            'approved_at' => $autoApprove ? Carbon::now() : null,
            'last_seen_at' => Carbon::now(),
        ]);
    }

    /**
     * Dipanggil oleh GET /api/v1/device/status (cek berkala dari mobile,
     * lihat rancangan poin 5) -- selalu memperbarui last_seen_at terlepas
     * dari status, supaya admin bisa melihat device pending/revoked pun
     * masih "hidup" dan mencoba menghubungi server. TIDAK melempar
     * exception di sini -- endpoint ini murni pelaporan status apa adanya
     * (200 selalu), sisi mobile-lah yang memutuskan blokir/tidak dari
     * `status` di responsnya (lihat DeviceStatusCache di pos_mobile).
     */
    public function checkStatus(Device $device): Device
    {
        $device->update(['last_seen_at' => Carbon::now()]);

        return $device->fresh();
    }

    public function approve(Device $device, User $admin): Device
    {
        $device->update([
            'status' => Device::STATUS_APPROVED,
            'approved_by_user_id' => $admin->id,
            'approved_at' => Carbon::now(),
            'revoked_by_user_id' => null,
            'revoked_at' => null,
        ]);

        return $device->fresh();
    }

    /**
     * Mencabut device -- DUA lapis penegakan (lihat rancangan poin 7):
     * (1) instan, kalau device sedang online: semua personal_access_tokens
     * yang tertaut ke device_id ini dihapus di transaksi yang sama, jadi
     * request berikutnya dari device itu langsung 401 (token sudah tidak
     * ada) tanpa menunggu cek berkala. (2) kalau device sedang offline saat
     * dicabut, tenggang 7 hari di sisi mobile (lihat docs/PRINCIPLES.md
     * pos_mobile & DeviceStatusCache) jadi jaring pengaman kedua.
     */
    public function revoke(Device $device, User $admin): Device
    {
        DB::transaction(function () use ($device, $admin) {
            $device->update([
                'status' => Device::STATUS_REVOKED,
                'revoked_by_user_id' => $admin->id,
                'revoked_at' => Carbon::now(),
            ]);

            PersonalAccessToken::where('device_id', $device->device_id)->delete();
        });

        return $device->fresh();
    }
}
