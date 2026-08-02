<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BOM per variasi (Tahap 2 dari fitur Variasi Berbayar) -- mirror
     * PERSIS `product_components` (lihat migrasi
     * `2026_07_03_235440_create_product_components_table`), sengaja bukan
     * struktur baru: sebuah variasi berbahan (mis. "Bawa Pulang" = 1x item
     * "gelas plastik") konsumsi komponennya lewat
     * `InventoryService::recordOutbound()` yang SAMA dipakai
     * `product_components`, jadi bentuk tabelnya juga sama.
     *
     * `cascadeOnDelete` ke `product_variations` (komponen ikut hilang
     * bersama variasinya) -- BEDA dari `sale_line_variations` yang
     * `restrictOnDelete` ke `product_variations`, karena tabel INI tidak
     * pernah direferensikan balik oleh transaksi manapun (HPP yang sudah
     * terjadi dibekukan sebagai angka di `sale_line_variations.hpp_snapshot`,
     * bukan pointer ke baris di sini) -- sama alasan `product_components`
     * boleh `cascadeOnDelete` ke `products` walau produknya sendiri sudah
     * pernah terjual.
     *
     * Variasi TANPA baris di tabel ini -> HPP-nya 0, identik perilaku
     * Tahap 1 (tidak ada flag terpisah "has_bom", keberadaan barisnya
     * sendiri sudah jadi sumber kebenaran -- sama seperti produk).
     */
    public function up(): void
    {
        Schema::create('product_variation_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained('product_variations')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variation_components');
    }
};
