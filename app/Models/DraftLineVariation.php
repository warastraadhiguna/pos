<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian variasi terpilih untuk satu DraftLine -- lihat docblock
 * migrasinya. Selalu blind-replace mengikuti baris induknya, tidak punya
 * identitas/versi sendiri.
 */
#[Fillable(['draft_line_id', 'variation_id', 'name_snapshot', 'price_snapshot'])]
class DraftLineVariation extends Model
{
    protected function casts(): array
    {
        return [
            'price_snapshot' => 'decimal:4',
        ];
    }

    public function draftLine(): BelongsTo
    {
        return $this->belongsTo(DraftLine::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
