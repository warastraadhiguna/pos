<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sku',
    'name',
    'costing_type',
    'base_uom_id',
    'purchase_uom_id',
    'standard_cost',
    'inventory_account_id',
    'item_category_id',
    'is_active',
])]
class Item extends Model
{
    protected function casts(): array
    {
        return [
            'standard_cost' => 'decimal:4',
            'is_active' => 'boolean',
            // Sama alasannya dengan `Account::parent_id` -- foreign key
            // biasa (bukan primary key) tidak otomatis di-cast Eloquent,
            // dan sebagian driver PDO/MySQL bisa mengembalikannya sebagai
            // string mentah. `base_uom_id` dibandingkan strict (`===`)
            // terhadap `Uom::id` (int) di InventoryService/PurchaseService
            // -- tanpa cast ini, perbandingan itu rentan bug yang sama
            // persis dengan "Akun [1-1000] bukan Kas/Bank yang aktif".
            'base_uom_id' => 'integer',
            'purchase_uom_id' => 'integer',
        ];
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'base_uom_id');
    }

    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function productComponents(): HasMany
    {
        return $this->hasMany(ProductComponent::class);
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockOpnameLines(): HasMany
    {
        return $this->hasMany(StockOpnameLine::class);
    }
}
