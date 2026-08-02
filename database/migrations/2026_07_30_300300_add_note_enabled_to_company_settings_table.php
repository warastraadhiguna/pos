<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saklar global fitur Catatan + Template -- pola identik
     * member_enabled/table_enabled: default FALSE, OFF berarti field
     * catatan tidak muncul di mobile/web/nota, DAN template tidak ikut
     * ditarik saat sync (lihat Api\ProductController meta +
     * HttpMasterDataSyncRepository di mobile).
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('note_enabled')->default(false)->after('table_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('note_enabled');
        });
    }
};
