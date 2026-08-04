<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DraftLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'local_uuid' => $this->local_uuid,
            'product_id' => $this->product_id,
            'product_name_snapshot' => $this->product_name_snapshot,
            'qty' => $this->qty,
            'unit_price' => $this->unit_price,
            'tax_rate' => $this->tax_rate,
            'line_total' => $this->line_total,
            'note' => $this->note,
            'is_printed' => $this->is_printed,
            'is_deleted' => $this->is_deleted,
            'content_updated_at' => $this->content_updated_at?->toIso8601String(),
            'variations' => $this->whenLoaded('variations', fn () => $this->variations->map(fn ($v) => [
                'variation_id' => $v->variation_id,
                'name' => $v->name_snapshot,
                'price' => $v->price_snapshot,
            ])),
        ];
    }
}
