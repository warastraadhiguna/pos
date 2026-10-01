<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `consignment_accrual_id` nullable -- sama konvensi
     * supplier_payment_allocations.goods_receipt_id: null berarti uang
     * muka/belum teralokasi ke accrual manapun.
     */
    public function up(): void
    {
        Schema::create('consignment_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_payment_id')->constrained('consignment_payments')->cascadeOnDelete();
            $table->foreignId('consignment_accrual_id')->nullable()->constrained('consignment_accruals')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_payment_allocations');
    }
};
