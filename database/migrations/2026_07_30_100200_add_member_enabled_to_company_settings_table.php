<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saklar global fitur Member/Pelanggan -- default FALSE (fitur baru,
     * tidak boleh tiba-tiba muncul di kasir manapun sampai admin sadar
     * mengaktifkannya). OFF berarti field pelanggan tidak muncul di
     * mobile/web maupun di struk, DAN data member tidak ikut ditarik saat
     * sync (lihat Api\ProductController meta + HttpMasterDataSyncRepository
     * di mobile) -- bukan cuma disembunyikan tampilannya.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('member_enabled')->default(false)->after('mobile_print_receipt');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('member_enabled');
        });
    }
};
