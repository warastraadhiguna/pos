<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            // Disiapkan sekarang untuk multi-outlet, saat ini selalu bernilai 1.
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            // Akun beban (type=expense) yang dipilih kasir/admin -- lihat
            // ExpenseService untuk validasi bahwa ini harus akun expense,
            // bukan akun HPP/Selisih Persediaan yang reserved untuk posting
            // sistem (PurchaseService/StockOpnameService).
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            // Siapa yang mencatat -- murni jejak, bukan audit log formal
            // (tidak ada tabel log terpisah untuk fitur ini, beda dengan
            // company_setting_logs).
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->string('payment_method', 50)->default('cash');
            $table->string('description');
            $table->string('payee')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
