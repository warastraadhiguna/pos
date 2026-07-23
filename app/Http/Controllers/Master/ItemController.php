<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Uom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Master/Items/Index', [
            'items' => Item::with(['baseUom', 'purchaseUom', 'itemCategory'])->orderBy('sku')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/Items/Form', [
            'item' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        Item::create($data);

        return Redirect::route('master.items.index')->with('success', 'Item berhasil ditambahkan.');
    }

    public function edit(Item $item): Response
    {
        return Inertia::render('Master/Items/Form', [
            'item' => $item,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $this->validateData($request, $item->id);

        $item->update($data);

        return Redirect::route('master.items.index')->with('success', 'Item berhasil diperbarui.');
    }

    public function destroy(Item $item): RedirectResponse
    {
        return $this->deleteOrFail($item, 'master.items.index', 'Item');
    }

    /**
     * Backs the searchable item picker on the Product form. Deliberately
     * never returns the full item list — with a large catalog that would
     * mean shipping thousands of rows to the browser on every page load
     * just so one can be picked. Instead the client sends what's typed so
     * far and gets back a capped, relevant slice.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));

        $items = Item::with('baseUom:id,code')
            ->where('is_active', true)
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($builder) use ($query) {
                    $builder->where('sku', 'like', "%{$query}%")
                        ->orWhere('name', 'like', "%{$query}%");
                });
            })
            ->orderBy('sku')
            ->limit(20)
            ->get(['id', 'sku', 'name', 'base_uom_id', 'purchase_uom_id']);

        return response()->json($items);
    }

    /**
     * Minimal item creation used by the "Jual Apa Adanya" picker on the
     * Product form, so a resale-as-is item doesn't require leaving the page.
     * Defaults costing_type to stocked (it has a shelf, it has stock),
     * purchase_uom to the same as base_uom (the common resale case: bought
     * and sold in the same unit), and resolves the inventory account by
     * code rather than hardcoding an id, per docs/PRINCIPLES.md.
     *
     * Validation errors are caught and returned as JSON explicitly — this
     * app's exception handler only auto-renders JSON for `/api/*` routes
     * (see bootstrap/app.php's shouldRenderJsonWhen), so a plain
     * $request->validate() here would otherwise produce a redirect-back
     * response instead of the 422 JSON this fetch-based picker expects.
     */
    public function quickCreate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'sku' => ['required', 'string', 'max:255', 'unique:items,sku'],
                'name' => ['required', 'string', 'max:255'],
                'base_uom_id' => ['required', 'exists:uoms,id'],
                'standard_cost' => ['required', 'numeric', 'min:0'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $inventoryAccount = Account::where('code', '1-1200')->firstOrFail();

        $item = Item::create([
            'sku' => $validated['sku'],
            'name' => $validated['name'],
            'costing_type' => 'stocked',
            'base_uom_id' => $validated['base_uom_id'],
            'purchase_uom_id' => $validated['base_uom_id'],
            'standard_cost' => $validated['standard_cost'],
            'inventory_account_id' => $inventoryAccount->id,
        ]);

        return response()->json($item);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:255', 'unique:items,sku,'.$ignoreId],
            'name' => ['required', 'string', 'max:255'],
            'costing_type' => ['required', 'in:stocked,cost_only'],
            'base_uom_id' => ['required', 'exists:uoms,id'],
            'purchase_uom_id' => ['required', 'exists:uoms,id'],
            'standard_cost' => ['required', 'numeric', 'min:0'],
            'inventory_account_id' => ['required', 'exists:accounts,id'],
            'item_category_id' => ['nullable', 'exists:item_categories,id'],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /**
     * @return array{uoms: \Illuminate\Support\Collection, accounts: \Illuminate\Support\Collection, itemCategories: \Illuminate\Support\Collection}
     */
    private function formOptions(): array
    {
        return [
            'uoms' => Uom::orderBy('code')->get(),
            'accounts' => Account::where('type', 'asset')->orderBy('code')->get(),
            'itemCategories' => ItemCategory::orderBy('name')->get(),
        ];
    }
}
