<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role Developer (hidden super-admin, lihat rancangan yang disetujui)
 * tersembunyi dari halaman ini SIMETRIS dengan RoleController: akunnya
 * tidak muncul di daftar, role-nya tidak muncul di dropdown, dan akun
 * developer tidak bisa disentuh langsung lewat ID (edit/update/destroy)
 * -- Kelola Pengguna murni untuk user BISNIS (Admin/Manajer/Kasir/custom),
 * akun Developer dikelola khusus lewat `php artisan developer:create`.
 */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::with(['role:id,name', 'outlet:id,name'])
                ->whereDoesntHave('role', fn ($q) => $q->where('is_developer', true))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role_id', 'outlet_id']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Form', [
            'user' => null,
            'roles' => Role::where('is_developer', false)->orderBy('name')->get(['id', 'name']),
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // Toko satu lokasi (default) tidak melihat field cabang sama
            // sekali di form ini -- pola sama saklar fitur opsional lain
            // (member/table/note), lihat rancangan Multi-Cabang poin 6.
            'multiBranchEnabled' => CompanySetting::current()->multi_branch_enabled,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, null);
        $data['password'] = $request->validate(['password' => ['required', 'string', 'min:8']])['password'];

        User::create($data);

        return Redirect::route('users.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): Response
    {
        abort_if($user->role?->is_developer, 404);

        return Inertia::render('Users/Form', [
            'user' => $user->only(['id', 'name', 'email', 'role_id', 'outlet_id']),
            'roles' => Role::where('is_developer', false)->orderBy('name')->get(['id', 'name']),
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'multiBranchEnabled' => CompanySetting::current()->multi_branch_enabled,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->role?->is_developer, 404);

        $data = $this->validateData($request, $user);

        $password = $request->validate(['password' => ['nullable', 'string', 'min:8']])['password'];
        if (! empty($password)) {
            $data['password'] = $password;
        }

        $user->update($data);

        return Redirect::route('users.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->role?->is_developer, 404);

        if ($user->id === auth()->id()) {
            return Redirect::route('users.index')->with('error', 'Anda tidak bisa menghapus akun Anda sendiri.');
        }

        return $this->deleteOrFail($user, 'users.index', 'Pengguna');
    }

    private function validateData(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            // Role Developer TIDAK BOLEH diberikan lewat form ini (satu-
            // satunya jalur resmi adalah `php artisan developer:create`) --
            // dicegah di SERVER, bukan cuma dengan menyembunyikannya dari
            // dropdown, supaya POST mentah (role_id dikirim manual, lewat
            // dropdown yang disembunyikan atau tidak) tetap ditolak.
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(fn ($query) => $query->where('is_developer', false)),
            ],
            // Multi-Cabang Lapisan 1 -- nullable = tidak ditugaskan ke satu
            // cabang (admin/manajer yang mengawasi banyak cabang, ATAU
            // "belum diatur" -- lihat docblock User::outlet()). Belum
            // di-enforce di logika kasir mana pun sampai Lapisan 3.
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
        ]);
    }
}
