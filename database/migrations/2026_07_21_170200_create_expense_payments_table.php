<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            // 1 pembayaran = 1 catatan beban spesifik (boleh parsial, boleh
            // dicicil lewat beberapa baris) -- BUKAN alokasi FIFO lintas
            // banyak catatan seperti supplier_payment_allocations, karena
            // beban biasanya tagihan diskrit (tagihan listrik bulan Juli),
            // bukan running-tab lintas banyak transaksi. Lihat
            // ExpensePaymentService untuk detail keputusan ini.
            $table->foreignId('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->string('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
    }
};
