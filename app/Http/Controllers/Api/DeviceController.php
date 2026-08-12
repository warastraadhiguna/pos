<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cek berkala (poin 5 rancangan Device Binding) -- dipanggil mobile client
 * setiap kali sync ada (lihat SyncStatusController.triggerSync di
 * pos_mobile), BUKAN lewat timer terpisah. Selalu 200 -- keputusan
 * blokir/lanjut sepenuhnya di sisi mobile berdasarkan `status` di respons
 * ini digabung dengan tenggang offline 7 hari yang tersimpan lokal.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $devices) {}

    public function status(Request $request): JsonResponse
    {
        $deviceId = $request->user()->currentAccessToken()->device_id;

        $device = Device::where('device_id', $deviceId)->firstOrFail();

        $device = $this->devices->checkStatus($device);

        return response()->json([
            'status' => $device->status,
            'checked_at' => $device->last_seen_at->toIso8601String(),
        ]);
    }
}
