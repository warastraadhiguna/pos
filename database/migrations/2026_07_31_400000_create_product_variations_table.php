<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Variasi Berbayar -- fitur keempat dari rangkaian besar (setelah
     * member, meja, catatan). TAHAP 1 dari 2: variasi cuma menambah HARGA
     * JUAL (`additional_price`), belum stok/HPP -- lihat docblock migrasi
     * `create_sale_line_variations_table` untuk bagaimana Tahap 2 (BOM per
     * variasi) menempel di atas skema ini tanpa membongkarnya.
     *
     * Per-produk lewat `product_id` (cascadeOnDelete -- variasi tidak
     * relevan lagi begitu produknya sendiri dihapus). Begitu sebuah
     * variasi dipakai di transaksi, `sale_line_variations.variation_id`
     * (restrictOnDelete) secara transitif juga mengunci penghapusan
     * PRODUK-nya, sama seperti sale_lines.product_id sudah restrict
     * duluan -- tidak ada celah integritas.
     */
    public function up(): void
    {
        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('additional_price', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variations');
    }
};
