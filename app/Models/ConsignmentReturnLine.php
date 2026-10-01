<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consignment_return_id', 'item_id', 'qty', 'hpp_value'])]
class ConsignmentReturnLine extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'hpp_value' => 'decimal:4',
        ];
    }

    public function consignmentReturn(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReturn::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
