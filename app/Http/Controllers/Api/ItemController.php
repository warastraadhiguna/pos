<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemResource;
use App\Http\Resources\ItemStockResource;
use App\Models\Item;
use App\Models\StockMovement;
use App\Support\SyncWatermark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    /**
     * Item master data (sku, name, category, unit) for the mobile client to
     * cache offline. Same ?updated_since= incremental sync as products.
     * Deliberately excludes stock — see stock() below for why that's a
     * separate, non-incremental endpoint.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['updated_since' => ['nullable', 'date']]);
        $syncedAt = SyncWatermark::now();

        $items = SyncWatermark::applyIncrementalFilter(
            Item::with(['baseUom:id,code', 'itemCategory:id,name']),
            $validated['updated_since'] ?? null,
        )->orderBy('id')->get();

        return ItemResource::collection($items)
            ->additional(['meta' => ['synced_at' => $syncedAt->toIso8601String()]]);
    }

    /**
     * Current stock + moving-average cost per item, as a full snapshot —
     * NOT incremental. Stock changes on every sale from any device, so
     * "what changed since X" doesn't mean anything useful for it; the
     * client should just refetch this periodically. It's advisory only
     * (e.g. a low-stock badge in the UI) — the real stock deduction and
     * truth always happens server-side when a sale syncs, never on the
     * device, exactly like the web Kasir already works.
     *
     * Avoids N+1 the same way Web\InventoryReportController does: the
     * latest stock_movements row per item is resolved in a single query
     * (subquery for the last movement id per item, joined back), not via
     * InventoryService::currentStock() in a loop.
     */
    public function stock(Request $request): AnonymousResourceCollection
    {
        $warehouseId = (int) $request->query('warehouse_id', 1);
        $asOf = SyncWatermark::now();

        $latestMovementIds = StockMovement::query()
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('MAX(id) as id')
            ->groupBy('item_id');

        $latestByItem = StockMovement::query()
            ->joinSub($latestMovementIds, 'latest', 'stock_movements.id', '=', 'latest.id')
            ->get(['stock_movements.item_id', 'stock_movements.running_qty', 'stock_movements.running_average_cost'])
            ->keyBy('item_id');

        $rows = Item::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'costing_type', 'standard_cost'])
            ->map(function (Item $item) use ($latestByItem) {
                if ($item->costing_type === 'cost_only') {
                    return [
                        'item_id' => $item->id,
                        'stock' => null,
                        'average_cost' => (string) $item->standard_cost,
                    ];
                }

                $movement = $latestByItem->get($item->id);

                return [
                    'item_id' => $item->id,
                    'stock' => $movement?->running_qty ?? '0.0000',
                    'average_cost' => $movement?->running_average_cost ?? '0.0000',
                ];
            });

        return ItemStockResource::collection($rows)
            ->additional(['meta' => ['as_of' => $asOf->toIso8601String()]]);
    }
}
