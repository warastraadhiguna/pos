<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diskon nota (persen/Rupiah, per-transaksi) -- lihat rancangan fitur
     * Diskon & SaleService::createSale(). `discount_type`/`discount_value`
     * adalah INPUT mentah kasir ('percentage'|'amount' + angka yang
     * diketik); `discount_amount` adalah Rp yang benar-benar dipotong dari
     * Grand Total, hasil resolusi server (SATU sumber kebenaran, bukan
     * dipercaya dari klien -- disiplin sama seperti subtotal/tax_total/
     * grand_total sendiri). `subtotal`/`tax_total` yang tersimpan di baris
     * ini SUDAH versi setelah-diskon (dialokasikan proporsional) -- invarian
     * rekonsiliasi subtotal+tax_total==grand_total TIDAK berubah rumusnya.
     * Default 0/nullable untuk baris lama -- tidak pernah didiskon.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('discount_type', 20)->nullable()->after('change_amount');
            $table->decimal('discount_value', 18, 4)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 18, 4)->default(0)->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
        });
    }
};
