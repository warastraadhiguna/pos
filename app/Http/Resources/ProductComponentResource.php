<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->item_id,
            'qty' => $this->qty,
            'uom' => [
                'id' => $this->uom->id,
                'code' => $this->uom->code,
            ],
        ];
    }
}
