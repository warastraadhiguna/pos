<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rincian variasi terpilih untuk satu draft_line -- mirror
     * `sale_line_variations` (lihat docblock migrasinya), TANPA
     * `hpp_snapshot` (draft belum final, HPP baru relevan & dihitung
     * sungguhan saat SaleService::createSale() benar-benar menyerap
     * variasi ini, tidak berubah oleh langkah ini).
     *
     * TIDAK punya `local_uuid`/`content_updated_at` sendiri -- variasi
     * SELALU blind-replace mengikuti baris induknya (memilih ulang
     * variasi = perubahan konten draft_line itu sendiri, sudah tercakup
     * oleh `content_updated_at` induknya, lihat docblock
     * DraftSyncService::mergeLine()).
     *
     * `cascadeOnDelete` ke draft_line (pola sama sale_line_variations),
     * `restrictOnDelete` ke product_variations (variasi yang sedang
     * dipakai draft aktif tidak boleh dihapus permanen, admin nonaktifkan
     * saja lewat `is_active` -- pola sama semua referensi variasi lain).
     */
    public function up(): void
    {
        Schema::create('draft_line_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_line_id')->constrained('draft_lines')->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained('product_variations')->restrictOnDelete();
            $table->string('name_snapshot');
            $table->decimal('price_snapshot', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_line_variations');
    }
};
