<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Satu baris = satu tindakan pembatalan transaksi (lihat
 * `SaleService::voidSale()`). Anchor polymorphic (pola sama
 * `LedgerAdjustment`/`EquityTransaction`) untuk jurnal & stock_movements
 * PEMBALIK -- sale asli & jurnal aslinya TIDAK PERNAH disentuh/dihapus,
 * cuma statusnya diubah jadi `void`. Keberadaan baris ini untuk sebuah
 * `sale_id` JUGA jadi penanda idempoten (tidak bisa di-void dua kali).
 */
#[Fillable(['sale_id', 'voided_by_user_id', 'reason'])]
class SaleVoid extends Model
{
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
