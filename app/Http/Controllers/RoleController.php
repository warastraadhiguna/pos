<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role Developer (hidden super-admin, lihat rancangan yang disetujui) TIDAK
 * BOLEH pernah muncul atau bisa disentuh lewat editor role bebas ini --
 * dua hal terpisah yang KEDUANYA perlu ditutup:
 *
 * 1. Role itu sendiri disembunyikan dari index() & diblok 404 dari
 *    edit/update/destroy -- termasuk akses LANGSUNG lewat ID (route-model-
 *    bound, bukan cuma "tidak ada di daftar"), supaya menebak-nebak ID
 *    tidak berguna.
 * 2. Permission developer-only (`is_developer_only`) disembunyikan dari
 *    permissionGroups() DAN ditolak validasi permission_ids -- ini yang
 *    menutup celah admin memberi permission itu ke role Admin/Manajer/
 *    Kasir/custom MILIKNYA SENDIRI (yang tidak disembunyikan, jadi bisa
 *    diedit bebas) lewat form ini. Tanpa (2), (1) saja nyaris tidak
 *    berarti -- admin tidak perlu tahu apa pun soal role Developer untuk
 *    menggenggam akses yang sama lewat role-nya sendiri.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::where('is_developer', false)->withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Roles/Form', [
            'role' => null,
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, null);

        $role = Role::create(['name' => $data['name']]);
        $role->permissions()->sync($data['permission_ids']);

        return Redirect::route('roles.index')->with('success', 'Role berhasil ditambahkan.');
    }

    public function edit(Role $role): Response
    {
        abort_if($role->is_developer, 404);

        return Inertia::render('Roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permission_ids' => $role->permissions()->pluck('permissions.id'),
            ],
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->is_developer, 404);

        $data = $this->validateData($request, $role);

        $role->update(['name' => $data['name']]);
        $role->permissions()->sync($data['permission_ids']);

        return Redirect::route('roles.index')->with('success', 'Role berhasil diperbarui.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->is_developer, 404);

        return $this->deleteOrFail($role, 'roles.index', 'Role');
    }

    private function permissionGroups(): array
    {
        return Permission::where('is_developer_only', false)->orderBy('group')->orderBy('label')->get()->groupBy('group')->all();
    }

    private function validateData(Request $request, ?Role $role): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permission_ids' => ['array'],
            'permission_ids.*' => [
                'integer',
                Rule::exists('permissions', 'id')->where(fn ($query) => $query->where('is_developer_only', false)),
            ],
        ]);
    }
}
