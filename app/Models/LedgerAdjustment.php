<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Anchor polymorphic utk jurnal manual yang tidak punya dokumen sumber asli
 * (bukan Sale/GoodsReceipt/ExpensePayment/EquityTransaction/dst) -- lihat
 * migration `create_ledger_adjustments_table`.
 */
#[Fillable(['date', 'description', 'created_by_user_id'])]
class LedgerAdjustment extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }
}
