<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Terima Titipan" -- dokumen header untuk barang konsinyasi yang
     * masuk. Pola sama `goods_receipts`, TAPI beda total maknanya: tidak
     * pernah menimbulkan baris jurnal apa pun (lihat ConsignmentService)
     * karena toko belum memiliki/berutang apa pun untuk barang ini sampai
     * benar-benar terjual.
     */
    public function up(): void
    {
        Schema::create('consignment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_receipts');
    }
};
