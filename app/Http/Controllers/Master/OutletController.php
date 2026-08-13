<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Services\BranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * "Kelola Cabang" -- Multi-Cabang Lapisan 1. Pola sama `Master\TableController`
 * (CRUD + toggle aktif lewat form yang sama), bedanya `is_headquarters`
 * ditangani lewat `BranchService` (bukan mass-assignment langsung) karena
 * ada invarian "maksimal satu pusat" yang perlu dijaga -- lihat docblock
 * `BranchService`.
 */
class OutletController extends Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(): Response
    {
        return Inertia::render('Master/Outlets/Index', [
            'outlets' => Outlet::orderByDesc('is_headquarters')->orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/Outlets/Form', [
            'outlet' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->branches->createOutlet($this->validateData($request, null));

        return Redirect::route('master.outlets.index')->with('success', 'Cabang berhasil ditambahkan.');
    }

    public function edit(Outlet $outlet): Response
    {
        return Inertia::render('Master/Outlets/Form', [
            'outlet' => $outlet,
        ]);
    }

    public function update(Request $request, Outlet $outlet): RedirectResponse
    {
        try {
            $this->branches->updateOutlet($outlet, $this->validateData($request, $outlet));
        } catch (InvalidArgumentException $e) {
            return Redirect::back()->withInput()->with('error', $e->getMessage());
        }

        return Redirect::route('master.outlets.index')->with('success', 'Cabang berhasil diperbarui.');
    }

    /**
     * Blocked by the DB (restrictOnDelete on warehouses/sales/dst.
     * outlet_id) if this outlet already has data -- admin should
     * nonaktifkan (uncheck "Aktif" di form edit) instead, pola sama
     * Member/Product/Item/Meja.
     */
    public function destroy(Outlet $outlet): RedirectResponse
    {
        return $this->deleteOrFail($outlet, 'master.outlets.index', 'Cabang');
    }

    private function validateData(Request $request, ?Outlet $outlet): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('outlets', 'code')->ignore($outlet?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_headquarters' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_headquarters'] = $request->boolean('is_headquarters');

        return $data;
    }
}
