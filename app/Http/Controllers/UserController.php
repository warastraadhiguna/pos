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

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::with(['role:id,name', 'outlet:id,name'])->orderBy('name')->get(['id', 'name', 'email', 'role_id', 'outlet_id']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Form', [
            'user' => null,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
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
        return Inertia::render('Users/Form', [
            'user' => $user->only(['id', 'name', 'email', 'role_id', 'outlet_id']),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'multiBranchEnabled' => CompanySetting::current()->multi_branch_enabled,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
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
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            // Multi-Cabang Lapisan 1 -- nullable = tidak ditugaskan ke satu
            // cabang (admin/manajer yang mengawasi banyak cabang, ATAU
            // "belum diatur" -- lihat docblock User::outlet()). Belum
            // di-enforce di logika kasir mana pun sampai Lapisan 3.
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
        ]);
    }
}
