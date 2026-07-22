<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain array row (not an Eloquent model) — built from the bulk
 * item+stock query in Api\ItemController::stock(), which reads the latest
 * stock_movements row per item in a single query rather than calling
 * InventoryService::currentStock() in a loop.
 */
class ItemStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this['item_id'],
            'stock' => $this['stock'],
            'average_cost' => $this['average_cost'],
        ];
    }
}
