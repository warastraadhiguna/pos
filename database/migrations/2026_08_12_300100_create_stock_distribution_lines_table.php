<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `qty` selalu dalam base_uom item (bukan purchase_uom seperti
     * purchase_order_lines) -- distribusi memindahkan stok yang SUDAH
     * dilacak dalam base_uom, bukan membeli dari luar dengan satuan
     * kemasan sendiri, jadi tidak perlu konversi UOM sama sekali.
     *
     * `unit_cost` SENGAJA nullable, TIDAK diisi saat dokumen dibuat
     * (draft) -- HPP pusat baru ditentukan & dibekukan saat EKSEKUSI
     * (lihat `DistributionService::executeDistribution()`), karena HPP
     * bisa berubah antara draft dibuat dan dieksekusi (ada pembelian baru
     * masuk di antaranya). NULL berarti "belum dieksekusi"; begitu
     * `stock_distributions.status` jadi `completed`, kolom ini berisi HPP
     * pusat yang BENAR-BENAR dipakai saat itu (bukan tebakan).
     */
    public function up(): void
    {
        Schema::create('stock_distribution_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_distribution_id')->constrained('stock_distributions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_distribution_lines');
    }
};
