<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryReportController extends Controller
{
    /**
     * Read-only stock + moving-average-cost snapshot for every item.
     *
     * Avoids N+1 by resolving the latest stock_movements row per item in a
     * single query (subquery for the last movement id per item, joined back
     * to fetch running_qty/running_average_cost) instead of calling
     * InventoryService::currentStock()/currentAverageCost() per item.
     */
    public function index(Request $request): Response
    {
        $warehouseId = (int) $request->input('warehouse_id', 1);

        $latestMovementIds = StockMovement::query()
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('MAX(id) as id')
            ->groupBy('item_id');

        $latestByItem = StockMovement::query()
            ->joinSub($latestMovementIds, 'latest', 'stock_movements.id', '=', 'latest.id')
            ->get(['stock_movements.item_id', 'stock_movements.running_qty', 'stock_movements.running_average_cost'])
            ->keyBy('item_id');

        $items = Item::with(['itemCategory:id,name', 'baseUom:id,code'])
            ->orderBy('sku')
            ->get()
            ->map(function (Item $item) use ($latestByItem) {
                if ($item->costing_type === 'cost_only') {
                    return [
                        'id' => $item->id,
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'category' => $item->itemCategory?->name,
                        'costing_type' => $item->costing_type,
                        'uom' => $item->baseUom?->code,
                        'stock' => null,
                        'average_cost' => (string) $item->standard_cost,
                        'inventory_value' => null,
                    ];
                }

                $movement = $latestByItem->get($item->id);
                $stock = $movement?->running_qty ?? '0.0000';
                $averageCost = $movement?->running_average_cost ?? '0.0000';

                return [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'category' => $item->itemCategory?->name,
                    'costing_type' => $item->costing_type,
                    'uom' => $item->baseUom?->code,
                    'stock' => $stock,
                    'average_cost' => $averageCost,
                    'inventory_value' => bcmul($stock, $averageCost, 4),
                ];
            });

        return Inertia::render('Reports/InventoryList', [
            'items' => $items,
            'warehouseId' => $warehouseId,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
