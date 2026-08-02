<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saklar global fitur Nomor Meja -- default FALSE (fitur baru, tidak
     * boleh tiba-tiba muncul di kasir manapun sampai admin sadar
     * mengaktifkannya). OFF berarti field meja tidak muncul di
     * mobile/web maupun di struk, DAN data meja tidak ikut ditarik saat
     * sync (lihat Api\ProductController meta + HttpMasterDataSyncRepository
     * di mobile) -- bukan cuma disembunyikan tampilannya. Pola identik
     * `member_enabled`.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('table_enabled')->default(false)->after('member_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('table_enabled');
        });
    }
};
