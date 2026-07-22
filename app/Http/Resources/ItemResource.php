<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'costing_type' => $this->costing_type,
            'base_uom' => $this->baseUom ? [
                'id' => $this->baseUom->id,
                'code' => $this->baseUom->code,
            ] : null,
            'item_category' => $this->itemCategory ? [
                'id' => $this->itemCategory->id,
                'name' => $this->itemCategory->name,
            ] : null,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
