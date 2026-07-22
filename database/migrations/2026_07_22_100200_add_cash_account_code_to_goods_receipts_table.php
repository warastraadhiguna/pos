<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            // Hanya relevan saat payment_method='cash' -- diabaikan
            // sepenuhnya untuk penerimaan kredit (tetap Cr Hutang Usaha,
            // tidak berubah oleh fitur ini). Default Kas untuk baris lama.
            $table->string('cash_account_code', 20)->default('1-1000')->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('cash_account_code');
        });
    }
};
