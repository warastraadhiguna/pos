<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variasi berbayar milik satu Product (mis. "Gelas Besar" +2.000 untuk Es
 * Teh) -- TAHAP 1: cuma `additional_price`, belum ada BOM/komponen sama
 * sekali (lihat docblock migrasi `create_sale_line_variations_table` untuk
 * bagaimana Tahap 2 menempel di atas ini tanpa membongkar).
 */
#[Fillable(['product_id', 'name', 'additional_price', 'is_active'])]
class ProductVariation extends Model
{
    protected function casts(): array
    {
        return [
            'additional_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleLineVariations(): HasMany
    {
        return $this->hasMany(SaleLineVariation::class, 'variation_id');
    }

    /**
     * BOM Tahap 2 -- lihat docblock migrasi
     * `create_product_variation_components_table`. Kosong berarti variasi
     * ini tidak konsumsi apa pun, HPP-nya 0 (identik Tahap 1).
     */
    public function components(): HasMany
    {
        return $this->hasMany(ProductVariationComponent::class, 'variation_id');
    }
}
