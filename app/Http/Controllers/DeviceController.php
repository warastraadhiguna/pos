<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Outlet;
use App\Services\DeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Kelola Perangkat" -- Device Binding. Route ADA
 * (/pengaturan/perangkat) tapi SENGAJA TIDAK dicantumkan di navGroups
 * (AuthenticatedLayout.jsx) -- rancangan yang disetujui secara eksplisit
 * meminta halaman ini tanpa tautan di mana pun, admin mengetik URL-nya
 * langsung (didokumentasikan di docs/DEVICE_BINDING.md). Tetap digerbangi
 * permission:devices.manage seperti semua halaman admin lain -- "tersembunyi"
 * di sini murni UX, bukan pengganti otorisasi.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $devices) {}

    public function index(): Response
    {
        $devices = Device::with(['outlet', 'registeredBy', 'approvedBy', 'revokedBy'])
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'revoked')")
            ->orderByDesc('last_seen_at')
            ->get();

        return Inertia::render('Pengaturan/Perangkat/Index', [
            'devices' => $devices,
            // Multi-Cabang Lapisan 1 -- daftar cabang aktif untuk pemilih
            // di tiap baris device (lihat DeviceService::assignOutlet()).
            // Selalu dimuat (murah, sama pola bankAccounts di
            // SettingController) -- tidak masalah walau multi_branch_enabled
            // mati, halaman ini toh sudah tersembunyi dari nav.
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function approve(Device $device): RedirectResponse
    {
        $this->devices->approve($device, request()->user());

        return back()->with('success', "Perangkat [{$device->device_id}] disetujui.");
    }

    public function revoke(Device $device): RedirectResponse
    {
        $this->devices->revoke($device, request()->user());

        return back()->with('success', "Perangkat [{$device->device_id}] dicabut aksesnya.");
    }

    public function assignOutlet(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
        ]);

        $this->devices->assignOutlet($device, $data['outlet_id'] ?? null);

        return back()->with('success', "Cabang perangkat [{$device->device_id}] diperbarui.");
    }
}
