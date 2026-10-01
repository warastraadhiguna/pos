<?php

namespace App\Http\Controllers\Konsinyasi;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentReceipt;
use App\Models\Warehouse;
use App\Services\ConsignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Terima Titipan" -- lihat rancangan fitur Konsinyasi. Struktur mirip
 * PurchaseOrderController, tapi lebih sederhana: tidak ada konsep PO
 * terpisah dari penerimaan (satu dokumen sekali jalan), karena konsinyasi
 * tidak punya proses "pesan dulu, terima belakangan" -- barangnya memang
 * cuma dititipkan.
 */
class ConsignmentReceiptController extends Controller
{
    public function __construct(
        private readonly ConsignmentService $consignments,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $dateFrom = $filters['date_from'] ?? now()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $search = $filters['search'] ?? '';

        $receipts = ConsignmentReceipt::with(['supplier', 'warehouse', 'lines.item'])
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            })
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Konsinyasi/Receipts/Index', [
            'receipts' => $receipts,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Konsinyasi/Receipts/Create', [
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->consignments->receive($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::route('konsinyasi.receipts.create')->withInput()->with('error', 'Gagal mencatat titipan: '.$e->getMessage());
        }

        return Redirect::route('konsinyasi.receipts.index')->with('success', 'Titipan berhasil dicatat.');
    }
}
