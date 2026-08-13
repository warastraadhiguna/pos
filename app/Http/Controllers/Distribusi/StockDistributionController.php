<?php

namespace App\Http\Controllers\Distribusi;

use App\Http\Controllers\Controller;
use App\Models\StockDistribution;
use App\Models\Warehouse;
use App\Services\DistributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Distribusi Stok" -- Multi-Cabang Lapisan 2. Pola PERSIS
 * Pembelian\PurchaseOrderController + GoodsReceiptController: dokumen
 * (intent) dan eksekusi (yang benar-benar menyentuh ledger) adalah dua
 * aksi terpisah. Beda dari GoodsReceipt: eksekusi di sini tidak butuh
 * form terpisah (tidak ada info tambahan yang perlu diisi saat eksekusi --
 * qty sudah ditentukan saat draft dibuat), cukup tombol konfirmasi di
 * halaman Show.
 */
class StockDistributionController extends Controller
{
    public function __construct(private readonly DistributionService $distributions) {}

    public function index(): Response
    {
        return Inertia::render('Distribusi/StockDistributions/Index', [
            'distributions' => StockDistribution::with(['sourceWarehouse', 'destWarehouse'])
                ->withCount('lines')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Distribusi/StockDistributions/Create', [
            // Gudang pusat & gudang cabang dipisah di sini supaya form
            // langsung menampilkan pilihan yang masuk akal (asal = pusat,
            // tujuan = salah satu cabang) tanpa admin perlu tahu aturan
            // "source wajib pusat" sendiri -- validasi tetap ada di
            // DistributionService sebagai jaring pengaman sungguhan.
            'sourceWarehouses' => Warehouse::whereHas('outlet', fn ($q) => $q->where('is_headquarters', true))
                ->orderBy('name')->get(),
            'destWarehouses' => Warehouse::whereHas('outlet', fn ($q) => $q->where('is_headquarters', false))
                ->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_warehouse_id' => ['required', 'exists:warehouses,id'],
            'dest_warehouse_id' => ['required', 'exists:warehouses,id'],
            'date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated['created_by_user_id'] = $request->user()->id;

        try {
            $distribution = $this->distributions->createDistribution($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::route('distribusi.stock-distributions.create')->with('error', 'Gagal membuat distribusi: '.$e->getMessage());
        }

        return Redirect::route('distribusi.stock-distributions.show', $distribution)->with('success', 'Distribusi berhasil dibuat -- belum dieksekusi, stok belum berpindah.');
    }

    public function show(StockDistribution $stockDistribution): Response
    {
        $stockDistribution->load(['sourceWarehouse', 'destWarehouse', 'createdBy', 'executedBy', 'lines.item']);

        $shortages = $stockDistribution->status === StockDistribution::STATUS_DRAFT
            ? $this->distributions->detectInsufficientStock($stockDistribution)
            : [];

        return Inertia::render('Distribusi/StockDistributions/Show', [
            'distribution' => $stockDistribution,
            'shortages' => collect($shortages)->map(fn (array $s) => [
                'item_sku' => $s['line']->item->sku,
                'requested' => $s['requested'],
                'available' => $s['available'],
            ])->values(),
        ]);
    }

    public function execute(Request $request, StockDistribution $stockDistribution): RedirectResponse
    {
        $validated = $request->validate([
            'confirm_insufficient_stock' => ['sometimes', 'boolean'],
        ]);

        // Kekurangan stok pusat tetap SAH (konsisten "stok boleh minus" di
        // seluruh aplikasi ini) tapi tidak boleh diam-diam diproses -- pola
        // PERSIS GoodsReceiptController::store() (over-receipt).
        $shortages = $this->distributions->detectInsufficientStock($stockDistribution);
        if ($shortages !== [] && ! ($validated['confirm_insufficient_stock'] ?? false)) {
            $itemSkus = collect($shortages)->map(fn (array $s) => $s['line']->item->sku)->join(', ');

            return Redirect::back()->with('error', "Stok pusat tidak cukup untuk: {$itemSkus}. Konfirmasi kekurangan stok diperlukan.");
        }

        try {
            $this->distributions->executeDistribution($stockDistribution, $request->user());
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal mengeksekusi distribusi: '.$e->getMessage());
        }

        return Redirect::route('distribusi.stock-distributions.show', $stockDistribution)->with('success', 'Distribusi berhasil dieksekusi -- stok sudah berpindah.');
    }

    public function cancel(StockDistribution $stockDistribution): RedirectResponse
    {
        try {
            $this->distributions->cancelDistribution($stockDistribution);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal membatalkan distribusi: '.$e->getMessage());
        }

        return Redirect::route('distribusi.stock-distributions.show', $stockDistribution)->with('success', 'Distribusi dibatalkan.');
    }
}
