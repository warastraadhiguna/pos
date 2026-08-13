<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'source_warehouse_id',
    'dest_warehouse_id',
    'date',
    'status',
    'notes',
    'created_by_user_id',
    'executed_by_user_id',
    'executed_at',
])]
class StockDistribution extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'executed_at' => 'datetime',
        ];
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'dest_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockDistributionLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    /**
     * Dua baris per item (keluar dari source_warehouse, masuk ke
     * dest_warehouse) tercipta di sini begitu status jadi `completed` --
     * lihat `DistributionService::executeDistribution()`. TIDAK ada
     * `journals()` relation di model ini (beda dari `GoodsReceipt`) --
     * distribusi TIDAK PERNAH membuat jurnal, lihat rancangan yang
     * disetujui poin 5.
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
