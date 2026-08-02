<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleLineVariationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'variation_id' => $this->variation_id,
            'name_snapshot' => $this->name_snapshot,
            'price_snapshot' => $this->price_snapshot,
        ];
    }
}
