<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Akun Kas/Bank yang menerima uang penjualan ini -- default
            // Kas untuk baris lama (semua penjualan sebelum fitur ini
            // memang tunai fisik). Lihat App\Services\CashAccountService.
            $table->string('cash_account_code', 20)->default('1-1000')->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('cash_account_code');
        });
    }
};
