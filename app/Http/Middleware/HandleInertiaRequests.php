<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->load('role.permissions');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'permissions' => $user?->permissionKeys() ?? [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Diisi Kasir\SaleController::store() setelah checkout
                // berhasil, dibaca Kasir/Index.jsx untuk menampilkan
                // tombol "Cetak Struk" transaksi yang baru saja dibuat.
                'sale_id' => fn () => $request->session()->get('sale_id'),
            ],
            // Dibagikan ke SEMUA halaman (bukan cuma Settings/Index) supaya
            // AuthenticatedLayout.jsx (sidebar, dirender di setiap halaman)
            // bisa menyembunyikan item menu "Kelola Member"/"Kelola Meja"/
            // "Kelola Template Catatan" saat saklar fiturnya di Pengaturan
            // sedang OFF -- lihat penajaman UX "akses pengelolaan fitur
            // opsional". Closure (lazy) & null saat guest (belum login,
            // mis. halaman Login) ATAU saat baris company_settings belum
            // ada sama sekali (mis. test yang tidak men-seed FoundationSeeder)
            // -- sengaja query manual (bukan CompanySetting::current(), yang
            // firstOrFail() dan akan meledak jadi 404 di SETIAP halaman
            // ber-auth kalau baris singleton-nya belum ada) supaya rute lain
            // yang tidak butuh sidebar sama sekali tidak ikut terdampak.
            'featureFlags' => function () use ($request) {
                if (! $request->user()) {
                    return null;
                }

                $setting = CompanySetting::query()->first();

                if (! $setting) {
                    return null;
                }

                return [
                    'member_enabled' => $setting->member_enabled,
                    'table_enabled' => $setting->table_enabled,
                    'note_enabled' => $setting->note_enabled,
                    'variation_enabled' => $setting->variation_enabled,
                    'draft_enabled' => $setting->draft_enabled,
                ];
            },
        ];
    }
}
