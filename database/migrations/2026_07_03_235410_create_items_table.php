<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            // stocked: dilacak via stock_movements. cost_only: HPP diambil dari standard_cost, tidak dilacak stok.
            $table->enum('costing_type', ['stocked', 'cost_only'])->default('stocked');
            $table->foreignId('base_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('purchase_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('standard_cost', 18, 4)->default(0);
            $table->foreignId('inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
