<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Retur Titipan" -- barang konsinyasi yang tidak laku, dikembalikan ke
     * pemiliknya. Sama seperti Terima Titipan, TIDAK PERNAH menimbulkan
     * jurnal apa pun (lihat ConsignmentService) -- barang ini bukan aset
     * yang pernah tercatat di Neraca, jadi keluarnya juga tidak berefek ke
     * akuntansi.
     */
    public function up(): void
    {
        Schema::create('consignment_returns', function (Blueprint $table) {
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
        Schema::dropIfExists('consignment_returns');
    }
};
