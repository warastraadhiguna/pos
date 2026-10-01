<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qty/unit_cost dalam base_uom item (pola sama goods_receipt_lines) --
     * unit_cost di sini yang dipakai InventoryService::recordInbound() agar
     * HPP penjualan nanti akurat sejak hari pertama, walau belum ada jurnal
     * yang diposting sama sekali untuk penerimaan ini.
     */
    public function up(): void
    {
        Schema::create('consignment_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_receipt_id')->constrained('consignment_receipts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_receipt_lines');
    }
};
