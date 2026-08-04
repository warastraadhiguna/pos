<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'local_uuid' => $this->local_uuid,
            'status' => $this->status,
            'member_id' => $this->member_id,
            'member_name' => $this->member_name_snapshot,
            'table_id' => $this->table_id,
            'table_name' => $this->table_name_snapshot,
            'note' => $this->note,
            // Nama pemegang lock LIVE-JOIN (bukan snapshot beku) -- nama
            // staf jarang berubah, beda dari member_name/table_name yang
            // sengaja dibekukan (lihat docblock migrasi `drafts`).
            'held_by_user_id' => $this->held_by_user_id,
            'held_by_name' => $this->whenLoaded('heldByUser', fn () => $this->heldByUser?->name),
            'held_by_device_label' => $this->held_by_device_label,
            'held_at' => $this->held_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lines' => DraftLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
