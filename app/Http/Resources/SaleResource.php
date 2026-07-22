<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'local_uuid' => $this->local_uuid,
            'date' => $this->date?->toDateString(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'grand_total' => $this->grand_total,
            'cash_received' => $this->cash_received,
            'change_amount' => $this->change_amount,
            'created_by_user_id' => $this->created_by_user_id,
            'device_label' => $this->device_label,
            'lines' => SaleLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
