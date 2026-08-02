<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rincian variasi terpilih untuk satu baris jual -- satu sale_line
     * bisa punya BEBERAPA baris di sini sekaligus (multi-variasi, mis. "Es
     * + Gelas Besar + Bawa Pulang" pada satu item). `name_snapshot`/
     * `price_snapshot` dibekukan SAAT transaksi -- pola identik
     * `product_name`/`member_name_snapshot`: rename/ubah harga variasi
     * setelahnya tidak boleh mengubah nota lama.
     *
     * `cascadeOnDelete` ke sale_line (baris ini hilang bersama baris
     * jualnya, wajar) tapi `restrictOnDelete` ke `product_variations`
     * (variasi yang pernah dipakai transaksi tidak boleh dihapus permanen
     * -- admin nonaktifkan saja lewat `is_active`, pola sama Member/
     * DiningTable).
     *
     * `hpp_snapshot` SENGAJA sudah ada di Tahap 1 walau SELALU diisi '0'
     * (SaleService belum menghitung apa pun dari variasi -- lihat
     * docblock SaleService::createSaleLine()) -- ini bukti desain
     * bertahap: Tahap 2 nanti (BOM per variasi, tabel baru
     * `product_variation_components` + loop tambahan di
     * SaleService::createSaleLine()) cukup MENGISI kolom ini dengan HPP
     * sungguhan dari komponen variasi, TANPA migrasi/kolom baru apa pun
     * di sini.
     */
    public function up(): void
    {
        Schema::create('sale_line_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_line_id')->constrained('sale_lines')->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained('product_variations')->restrictOnDelete();
            $table->string('name_snapshot');
            $table->decimal('price_snapshot', 18, 4);
            $table->decimal('hpp_snapshot', 18, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_line_variations');
    }
};
