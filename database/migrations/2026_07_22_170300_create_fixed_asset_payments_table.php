<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            // 1 pembayaran = 1 aset tetap spesifik (boleh parsial/dicicil)
            // -- mirror ExpensePaymentService/ExpensePayment persis: model
            // 1:1 sederhana, bukan alokasi FIFO lintas banyak aset.
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->string('cash_account_code', 20)->default('1-1000');
            $table->string('memo')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_payments');
    }
};
