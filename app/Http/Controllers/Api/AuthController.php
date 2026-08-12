<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DevicePendingException;
use App\Exceptions\DeviceRevokedException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Abilities granted to every mobile-issued token — exactly what the
     * mobile client does today (tarik master data / kirim penjualan / cek
     * status / cek status device, tahap 4a-4b + Device Binding), never
     * Sanctum's default `['*']` wildcard. A new sensitive endpoint added
     * later needs its own ability explicitly granted; it is NOT
     * auto-available to existing or new mobile tokens just because they're
     * authenticated.
     *
     * @var array<int, string>
     */
    private const MOBILE_ABILITIES = ['sync:pull', 'sync:push', 'sync:status', 'device:status'];

    public function __construct(private readonly DeviceService $devices) {}

    /**
     * Issue a Sanctum personal access token for the mobile client.
     *
     * device_name is optional but recommended — it becomes the token's
     * name, so distinct devices logged into the same account get
     * distinguishable tokens (revoke one without logging out every other
     * device) and Sale::device_label can later be read straight off
     * `currentAccessToken()->name` without the client resending it on
     * every single sale.
     *
     * device_id (Device Binding) is different from device_name: it's the
     * stable hardware identifier (Android ID) used to gate whether this
     * physical device is allowed to hold a session at all. Checked AFTER
     * credentials are verified (not before) so this endpoint can never be
     * used to anonymously probe/register devices with bad credentials — see
     * DeviceService::registerOrCheckLoginDevice(). A device that is
     * pending/revoked never gets a token, regardless of how correct the
     * password is.
     *
     * device_id is `nullable`, NOT required, deliberately — an APK built
     * before Device Binding shipped has no way to send it. Backend and
     * mobile app releases are never perfectly simultaneous across every
     * physical device in the field, so a null device_id here is treated as
     * "pre-Device-Binding client" and skips the check entirely (today's
     * behaviour, unchanged) rather than rejecting the login outright —
     * making device_id required would lock out every currently-installed
     * device the moment the backend deploys, before its APK is even
     * updated, which is exactly what the grace period (see
     * CompanySetting::deviceBindingGracePeriodActive()) exists to prevent.
     * Once every device is confirmed running an APK that sends device_id,
     * that null-bypass path simply stops being exercised in practice.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'device_label' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if ($credentials['device_id'] ?? null) {
            try {
                $this->devices->registerOrCheckLoginDevice($credentials['device_id'], $credentials['device_label'] ?? null, $user);
            } catch (DevicePendingException $e) {
                return response()->json([
                    'error_code' => 'device_pending',
                    'message' => 'Perangkat ini menunggu persetujuan admin. Hubungi admin untuk mengaktifkan perangkat ini.',
                ], 403);
            } catch (DeviceRevokedException $e) {
                return response()->json([
                    'error_code' => 'device_revoked',
                    'message' => 'Perangkat ini telah dicabut aksesnya. Hubungi admin.',
                ], 403);
            }
        }

        $tokenName = $credentials['device_name'] ?? 'mobile';

        $newToken = $user->createToken($tokenName, self::MOBILE_ABILITIES);
        if ($credentials['device_id'] ?? null) {
            $newToken->accessToken->device_id = $credentials['device_id'];
            $newToken->accessToken->save();
        }

        return response()->json([
            'token' => $newToken->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
