<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

// image_path/image_path_web/image_hash SENGAJA tidak ada di sini --
// diisi lewat ProductImageService (lihat komentar migrasinya), bukan
// mass-assignment biasa lewat form field product/qty/dll. Upload gambar
// adalah langkah terpisah (multipart), bukan bagian dari array field
// form yang sama.
#[Fillable(['name', 'barcode', 'sell_price', 'tax_rate_id', 'product_category_id', 'is_active'])]
class Product extends Model
{
    /**
     * Appended otomatis ke setiap serialisasi model ini (JSON API,
     * Inertia) -- satu-satunya tempat path relatif diterjemahkan jadi URL
     * absolut, dipakai baik oleh ProductResource (sync mobile, HANYA
     * image_url) maupun serialisasi model langsung (Kasir/Master, Inertia
     * meng-array-kan Eloquent model apa adanya termasuk $appends).
     */
    protected $appends = ['image_url', 'image_url_web'];

    /**
     * Bersihkan file gambar (kalau ada) saat produk BENAR-BENAR dihapus --
     * bukan lewat ProductImageService (yang menangani ganti/hapus gambar
     * SAAT produk masih ada) supaya penghapusan produk lewat jalur mana
     * pun (Master\ProductController::destroy(), tinker, dsb.) tidak pernah
     * meninggalkan file yatim di storage.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $product) {
            foreach ([$product->image_path, $product->image_path_web] as $path) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sell_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        );
    }

    protected function imageUrlWeb(): Attribute
    {
        return Attribute::get(
            fn () => $this->image_path_web ? Storage::disk('public')->url($this->image_path_web) : null,
        );
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class);
    }

    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }
}
