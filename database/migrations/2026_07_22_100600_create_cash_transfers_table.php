<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->date('date');
            // Kode akun asal/tujuan (mis. '1-1000'/'1-1100') -- string
            // biasa, sama seperti setiap referensi akun lain di sistem ini
            // (lihat App\Services\CashAccountService), bukan FK ke id.
            $table->string('from_account_code', 20);
            $table->string('to_account_code', 20);
            $table->decimal('amount', 18, 4);
            $table->string('memo')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};
