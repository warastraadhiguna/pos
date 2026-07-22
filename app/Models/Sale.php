<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'outlet_id',
    'warehouse_id',
    'created_by_user_id',
    'date',
    'occurred_at',
    'local_uuid',
    'device_label',
    'subtotal',
    'tax_total',
    'grand_total',
    'cash_received',
    'change_amount',
    'payment_method',
    'cash_account_code',
    'status',
    'source_type',
    'source_id',
])]
class Sale extends Model
{
    /**
     * Transient, never persisted — true when SaleService::createSale()
     * returned an already-existing sale (idempotent replay of a local_uuid
     * already on record) rather than one it just created. A real typed
     * property, not a magic attribute, so it can never accidentally end up
     * as a column value on a future save().
     */
    public bool $wasReplayed = false;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'occurred_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'cash_received' => 'decimal:4',
            'change_amount' => 'decimal:4',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }
}
