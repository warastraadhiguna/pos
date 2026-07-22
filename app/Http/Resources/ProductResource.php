<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'barcode' => $this->barcode,
            // Tax-inclusive: what the customer actually pays, PPN already
            // baked in. See ProductController::index()'s meta.ppn_active
            // and this product's own tax_rate for how a client should
            // estimate the tax portion — never add tax_rate on top of this.
            'sell_price' => $this->sell_price,
            'is_active' => $this->is_active,
            'tax_rate' => $this->taxRate ? [
                'id' => $this->taxRate->id,
                'name' => $this->taxRate->name,
                'rate' => $this->taxRate->rate,
            ] : null,
            'product_category' => $this->productCategory ? [
                'id' => $this->productCategory->id,
                'name' => $this->productCategory->name,
            ] : null,
            'components' => ProductComponentResource::collection($this->components),
            // HANYA thumbnail (~200px) -- versi web (~600px) murni untuk
            // pratinjau admin (Master/Products), tidak pernah dibutuhkan
            // mobile. `image_hash` menyertai `image_url` supaya HP tahu ada
            // gambar sama sekali tanpa perlu parse URL -- tapi invalidasi
            // cache-nya sendiri sudah otomatis lewat URL yang berubah tiap
            // upload (lihat docblock ProductImageService), bukan lewat
            // perbandingan hash ini secara eksplisit.
            'image_url' => $this->image_url,
            'image_hash' => $this->image_hash,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
