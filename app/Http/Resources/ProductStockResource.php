<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain array row (not an Eloquent model) — built from
 * InventoryService::producibleQtyForProducts() in Api\ProductController::stock().
 * Mirrors ItemStockResource's shape.
 */
class ProductStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this['product_id'],
            'producible_qty' => $this['producible_qty'],
        ];
    }
}
