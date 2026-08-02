<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan per-transaksi (mis. "antar ke meja 5") -- BEDA dari
     * member_name_snapshot/table_name_snapshot: tidak ada kolom `_id`
     * berpasangan di sini, karena catatan bukan referensi ke entitas lain
     * yang bisa berubah nama -- teksnya SENDIRI sudah snapshot yang benar
     * sejak awal (langsung diketik atau hasil isi-cepat dari template yang
     * lalu diedit bebas). Nullable, tanpa default -- kosong berarti kasir
     * tidak mengisi apa pun / fitur catatan nonaktif, baris "Catatan" di
     * nota dilewati sepenuhnya.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->text('note')->nullable()->after('table_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
