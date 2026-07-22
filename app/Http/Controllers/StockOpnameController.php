<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\StockOpname;
use App\Models\Warehouse;
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
    public function __construct(private readonly StockOpnameService $opnames) {}

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
        $stockOpname->load(['warehouse', 'lines.item.baseUom']);

        return Inertia::render('StockOpname/Show', [
            'stockOpname' => $stockOpname,
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
        ]);

        $countedQuantities = collect($validated['lines'])
            ->pluck('counted_qty', 'stock_opname_line_id')
            ->all();

        try {
            $this->opnames->postOpname($stockOpname, $countedQuantities, $validated['date']);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal posting stock opname: '.$e->getMessage());
        }

        return Redirect::route('stock-opname.show', $stockOpname)->with('success', 'Stock opname berhasil diposting.');
    }
}
