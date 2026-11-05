<?php

namespace App\Http\Controllers;

use App\Exceptions\StockChangedSinceOpnameStartedException;
use App\Exceptions\VoidConfirmationRequiredException;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\StockOpnameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class StockOpnameController extends Controller
{
    public function __construct(
        private readonly StockOpnameService $opnames,
        private readonly InventoryService $inventory,
    ) {}

    public function index(): Response
    {
        return Inertia::render('StockOpname/Index', [
            'opnames' => StockOpname::with('warehouse')->orderByDesc('id')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('StockOpname/Create', [
            'warehouses' => Warehouse::orderBy('name')->get(),
            // cost_only items have no tracked stock, so they can't be opname'd
            // (StockOpnameService::startOpname() rejects them outright).
            'items' => Item::where('is_active', true)
                ->where('costing_type', 'stocked')
                ->orderBy('sku')
                ->get(['id', 'sku', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'date' => ['required', 'date'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'exists:items,id'],
        ]);

        try {
            $opname = $this->opnames->startOpname($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::route('stock-opname.create')->with('error', 'Gagal memulai stock opname: '.$e->getMessage());
        }

        return Redirect::route('stock-opname.show', $opname)->with('success', 'Stock opname dimulai. Silakan isi hasil hitung fisik.');
    }

    public function show(StockOpname $stockOpname): Response
    {
        $stockOpname->load(['warehouse', 'lines.item.baseUom', 'cancelledBy:id,name']);

        // Hanya untuk draft: stok TERKINI (basis selisih saat posting) dan
        // mutasi sejak draft dibuat, supaya kasir tahu barang mana yang
        // harus dihitung ulang -- lihat StockOpnameService::postOpname().
        $isDraft = $stockOpname->status === 'draft';

        return Inertia::render('StockOpname/Show', [
            'stockOpname' => $stockOpname,
            'liveStock' => $isDraft
                ? $stockOpname->lines->mapWithKeys(fn ($line) => [
                    $line->item_id => $this->inventory->currentStock($line->item, $stockOpname->warehouse),
                ])
                : (object) [],
            'movementsSinceStart' => $isDraft ? (object) $this->opnames->stockMovementsSinceStart($stockOpname) : (object) [],
            // Untuk dialog konfirmasi "Batalkan Opname" -- lihat
            // StockOpnameService::cancelWarnings().
            'cancelWarnings' => $this->opnames->cancelWarnings($stockOpname),
        ]);
    }

    public function post(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_opname_line_id' => [
                'required',
                Rule::exists('stock_opname_lines', 'id')->where('stock_opname_id', $stockOpname->id),
            ],
            'lines.*.counted_qty' => ['required', 'numeric', 'min:0'],
            'confirm_stock_changed' => ['boolean'],
        ]);

        $countedQuantities = collect($validated['lines'])
            ->pluck('counted_qty', 'stock_opname_line_id')
            ->all();

        try {
            $this->opnames->postOpname($stockOpname, $countedQuantities, $validated['date'], $request->boolean('confirm_stock_changed'));
        } catch (StockChangedSinceOpnameStartedException $e) {
            return Redirect::back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal posting stock opname: '.$e->getMessage());
        }

        return Redirect::route('stock-opname.show', $stockOpname)->with('success', 'Stock opname berhasil diposting.');
    }

    /**
     * Batalkan opname -- lihat StockOpnameService::cancelOpname(). Alasan
     * wajib untuk opname yang sudah diposting (menulis jurnal pembalik);
     * `confirm` wajib true kalau pembatalan membuat stok minus.
     */
    public function cancel(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => [Rule::requiredIf($stockOpname->status === 'completed'), 'nullable', 'string', 'max:255'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->opnames->cancelOpname($stockOpname, $validated['reason'] ?? null, $request->user()->id, $request->boolean('confirm'));
        } catch (VoidConfirmationRequiredException $e) {
            return Redirect::back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal membatalkan stock opname: '.$e->getMessage());
        }

        return Redirect::route('stock-opname.show', $stockOpname)->with('success', 'Stock opname dibatalkan.');
    }
}
