<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'item_id',
    'warehouse_id',
    'date',
    'source_type',
    'source_id',
    'qty_in',
    'qty_out',
    'unit_cost',
    'running_qty',
    'running_average_cost',
])]
class StockMovement extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'qty_in' => 'decimal:4',
            'qty_out' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'running_qty' => 'decimal:4',
            'running_average_cost' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
