<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Nota" Hutang Konsinyasi per (Sale, Supplier) -- lihat docblock migrasi
 * pembuatnya untuk alasan kenapa tabel ini perlu ada sama sekali.
 */
#[Fillable(['sale_id', 'supplier_id', 'date', 'amount', 'voided_at'])]
class ConsignmentAccrual extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
