<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saklar global fitur Diskon nota (persen/Rupiah, diisi kasir di dialog
     * Bayar) -- pola identik draft_enabled/qris_enabled: default FALSE, OFF
     * berarti field Diskon tidak muncul sama sekali di kasir manapun, dan
     * SaleController::store() menolak sale yang membawa discount_value > 0
     * (lihat rancangan fitur Diskon).
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('discount_enabled')->default(false)->after('qris_cash_account_code');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('discount_enabled');
        });
    }
};
