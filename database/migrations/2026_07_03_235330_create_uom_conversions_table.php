<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_uom_id')->constrained('uoms')->cascadeOnDelete();
            $table->foreignId('to_uom_id')->constrained('uoms')->cascadeOnDelete();
            // to_qty = from_qty * factor
            $table->decimal('factor', 18, 4);
            $table->timestamps();

            $table->unique(['from_uom_id', 'to_uom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uom_conversions');
    }
};
