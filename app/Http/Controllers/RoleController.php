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

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::withCount('users')->orderBy('name')->get(),
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
        $data = $this->validateData($request, $role);

        $role->update(['name' => $data['name']]);
        $role->permissions()->sync($data['permission_ids']);

        return Redirect::route('roles.index')->with('success', 'Role berhasil diperbarui.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        return $this->deleteOrFail($role, 'roles.index', 'Role');
    }

    private function permissionGroups(): array
    {
        return Permission::orderBy('group')->orderBy('label')->get()->groupBy('group')->all();
    }

    private function validateData(Request $request, ?Role $role): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);
    }
}
