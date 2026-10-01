<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * hpp_value murni INFORMASI TAMPILAN (nilai barang yang dikembalikan,
     * dihitung dari rata-rata berjalan SAAT retur via
     * InventoryService::recordOutbound()) -- TIDAK PERNAH dibaca kode
     * akuntansi mana pun, tidak pernah diposting ke jurnal.
     */
    public function up(): void
    {
        Schema::create('consignment_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_return_id')->constrained('consignment_returns')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('hpp_value', 18, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_return_lines');
    }
};
