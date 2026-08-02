<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saklar global fitur Variasi Berbayar -- pola identik member_enabled/
     * table_enabled/note_enabled: default FALSE, OFF berarti pemilih
     * variasi tidak muncul di mobile/web. Data variasi itu sendiri TETAP
     * ikut sync via meta GET /products terlepas dari saklar ini (lihat
     * Api\ProductController) -- yang digating cuma tampilan UI-nya, sama
     * seperti kolom capacity/area DiningTable yang selalu ikut sync.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('variation_enabled')->default(false)->after('note_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('variation_enabled');
        });
    }
};
