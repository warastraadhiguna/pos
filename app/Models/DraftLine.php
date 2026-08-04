<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu baris item Draft -- lihat docblock migrasinya untuk arti
 * `local_uuid`/`content_updated_at`/`is_deleted`, yang bersama-sama jadi
 * dasar resolusi konflik per-item di `DraftSyncService::mergeLine()`.
 */
#[Fillable([
    'draft_id',
    'local_uuid',
    'product_id',
    'product_name_snapshot',
    'qty',
    'unit_price',
    'tax_rate',
    'line_total',
    'note',
    'is_printed',
    'is_deleted',
    'content_updated_at',
])]
class DraftLine extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'line_total' => 'decimal:4',
            'is_printed' => 'boolean',
            'is_deleted' => 'boolean',
            'content_updated_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(DraftLineVariation::class);
    }
}
