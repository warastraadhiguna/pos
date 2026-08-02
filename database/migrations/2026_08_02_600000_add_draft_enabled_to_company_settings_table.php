<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saklar global fitur Draft (nota belum final) -- pola identik
     * member_enabled/table_enabled/note_enabled/variation_enabled: default
     * FALSE, OFF berarti kasir tidak melihat tombol "Simpan Draft"/daftar
     * draft sama sekali, checkout selalu langsung seperti sebelum fitur ini
     * ada. BEDA dari toggle lain: draft murni fitur LOKAL mobile (SQLite),
     * kolom ini cuma dibaca lewat meta GET /products supaya HP tahu status
     * saklar tanpa perlu endpoint terpisah -- server sendiri tidak pernah
     * menyimpan/memproses draft apa pun (lihat rancangan fitur Draft).
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('draft_enabled')->default(false)->after('variation_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('draft_enabled');
        });
    }
};
