<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'outlet_id',
    'name',
    'category',
    'purchase_date',
    'acquisition_cost',
    'residual_value',
    'useful_life_months',
    'depreciation_method',
    'payment_method',
    'cash_account_code',
    'created_by_user_id',
])]
class FixedAsset extends Model
{
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'acquisition_cost' => 'decimal:4',
            'residual_value' => 'decimal:4',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FixedAssetPayment::class);
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }
}
