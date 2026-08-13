<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
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
            ...$this->identityFormOptions(),
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
            ...$this->identityFormOptions(),
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

    /**
     * Satukan pengaturan identitas (lihat rancangan yang disetujui) --
     * `multiBranchEnabled` menentukan tampilan form (lihat Outlets/Form.jsx:
     * cabang pusat menampilkan field identitas company_settings yang bisa
     * diedit LANGSUNG di sini kalau true, atau catatan+tautan ke Pengaturan
     * kalau false/edge-case akses langsung). `storeIdentity` mengisi nilai
     * AWAL field itu -- dipakai baik saat edit outlet yang MEMANG sudah
     * pusat, maupun saat create/edit outlet lain yang SEDANG dipromosikan
     * jadi pusat (checkbox "Cabang Pusat" dicentang), supaya admin melihat
     * nilai company_settings TERKINI sebagai titik awal, bukan form kosong
     * yang kalau disimpan diam-diam mengosongkan identitas toko.
     *
     * @return array{multiBranchEnabled: bool, storeIdentity: array{store_name: ?string, store_address: ?string, store_phone: ?string, store_footer: ?string}}
     */
    private function identityFormOptions(): array
    {
        $setting = CompanySetting::current();

        return [
            'multiBranchEnabled' => $setting->multi_branch_enabled,
            'storeIdentity' => [
                'store_name' => $setting->store_name,
                'store_address' => $setting->store_address,
                'store_phone' => $setting->store_phone,
                'store_footer' => $setting->receipt_footer,
            ],
        ];
    }

    private function validateData(Request $request, ?Outlet $outlet): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('outlets', 'code')->ignore($outlet?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            // Identitas struk (header) -- lihat rancangan yang disetujui.
            // Nullable & boleh dikosongkan kapan saja (mis. balik ke
            // "belum diisi") -- BranchService::resolveReceiptIdentity()
            // jatuh ke identitas company_settings global kalau kosong,
            // jadi mengosongkan field ini TIDAK PERNAH membuat struk
            // tanpa identitas. `phone`/`receipt_footer` = kolom outlet
            // (cabang NON-pusat); `store_*` = company_settings GLOBAL
            // (dipakai KALAU outlet ini pusat -- lihat
            // BranchService::pullReceiptIdentity()/saveReceiptIdentityIfHeadquarters(),
            // diabaikan total kalau bukan pusat).
            'phone' => ['nullable', 'string', 'max:255'],
            'receipt_footer' => ['nullable', 'string', 'max:255'],
            'store_name' => ['nullable', 'string', 'max:255'],
            'store_address' => ['nullable', 'string', 'max:255'],
            'store_phone' => ['nullable', 'string', 'max:255'],
            'store_footer' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_headquarters' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_headquarters'] = $request->boolean('is_headquarters');

        return $data;
    }
}
