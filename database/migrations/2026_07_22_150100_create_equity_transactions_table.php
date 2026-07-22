<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->date('date');
            // 'modal' (setoran pemilik) atau 'prive' (pengambilan pribadi) --
            // satu tabel untuk keduanya karena masing-masing adalah
            // peristiwa TUNGGAL yang selesai saat itu juga (bukan proses
            // dua-langkah seperti expenses/expense_payments), jadi tidak
            // ada relasi antar-baris yang butuh tabel terpisah. Lihat
            // App\Services\EquityTransactionService untuk jurnal masing-masing.
            $table->string('type', 20);
            $table->decimal('amount', 18, 4);
            // Akun Kas/Bank yang menerima (modal) atau membayar (prive) --
            // lihat App\Services\CashAccountService.
            $table->string('cash_account_code', 20);
            $table->string('description')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_transactions');
    }
};
