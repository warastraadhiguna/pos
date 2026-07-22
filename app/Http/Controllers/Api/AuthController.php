<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Abilities granted to every mobile-issued token — exactly what the
     * mobile client does today (tarik master data / kirim penjualan / cek
     * status, tahap 4a-4b), never Sanctum's default `['*']` wildcard. A new
     * sensitive endpoint added later needs its own ability explicitly
     * granted; it is NOT auto-available to existing or new mobile tokens
     * just because they're authenticated.
     *
     * @var array<int, string>
     */
    private const MOBILE_ABILITIES = ['sync:pull', 'sync:push', 'sync:status'];

    /**
     * Issue a Sanctum personal access token for the mobile client.
     *
     * device_name is optional but recommended — it becomes the token's
     * name, so distinct devices logged into the same account get
     * distinguishable tokens (revoke one without logging out every other
     * device) and Sale::device_label can later be read straight off
     * `currentAccessToken()->name` without the client resending it on
     * every single sale.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $tokenName = $credentials['device_name'] ?? 'mobile';

        return response()->json([
            'token' => $user->createToken($tokenName, self::MOBILE_ABILITIES)->plainTextToken,
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
