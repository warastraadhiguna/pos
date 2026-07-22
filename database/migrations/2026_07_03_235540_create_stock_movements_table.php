<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // Disiapkan sekarang untuk multi-warehouse, saat ini selalu bernilai 1.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('date');
            // Dokumen sumber pergerakan stok (GoodsReceipt, Sale, StockOpname, dll).
            $table->morphs('source');
            $table->decimal('qty_in', 18, 4)->default(0);
            $table->decimal('qty_out', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            // Stok berjalan & HPP rata-rata bergerak (Moving Average) setelah movement ini.
            $table->decimal('running_qty', 18, 4)->default(0);
            $table->decimal('running_average_cost', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
