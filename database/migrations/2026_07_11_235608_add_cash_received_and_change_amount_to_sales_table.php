<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Uang tunai diterima kasir & kembalian (checkout mobile POS).
            // Default 0 untuk baris lama (web Kasir tidak melacak ini sama
            // sekali — lihat SaleService::createSale(), field ini opsional
            // di sana supaya jalur web tidak terpengaruh).
            $table->decimal('cash_received', 18, 4)->default(0)->after('grand_total');
            $table->decimal('change_amount', 18, 4)->default(0)->after('cash_received');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['cash_received', 'change_amount']);
        });
    }
};
