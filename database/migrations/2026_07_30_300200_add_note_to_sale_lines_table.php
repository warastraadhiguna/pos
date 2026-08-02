<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan per-item (mis. "es sedikit, jangan manis") -- lihat docblock
     * migrasi `add_note_to_sales_table` untuk alasan tidak adanya kolom
     * `_id` berpasangan. Nullable, tanpa default -- baris "→ catatan" di
     * bawah nama item pada nota dilewati kalau kosong.
     */
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->text('note')->nullable()->after('hpp_total');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
