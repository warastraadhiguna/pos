<?php

namespace App\Http\Middleware;

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
        ];
    }
}
