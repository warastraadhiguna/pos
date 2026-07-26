<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saklar global "cetak struk otomatis di HP kasir setelah checkout" --
     * default true (perilaku hari ini, tidak berubah sampai admin
     * mematikannya secara sadar). Dimatikan saat toko tidak membawa
     * printer (mis. jualan di luar/event) supaya checkout tidak pernah
     * mencoba menghubungi printer sama sekali -- bukan cuma menyembunyikan
     * error-nya, tapi benar-benar melewati percobaan cetak.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('mobile_print_receipt')->default(true)->after('payment_quick_amounts');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('mobile_print_receipt');
        });
    }
};
