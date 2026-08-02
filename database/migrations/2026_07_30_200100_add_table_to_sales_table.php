<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `table_name_snapshot` dibekukan SAAT transaksi (sama prinsipnya dengan
     * `sales.member_name_snapshot`) -- rename/nonaktifkan meja tidak boleh
     * mengubah nota lama. `table_id` disimpan TERPISAH, khusus untuk fitur
     * status meja dan draft (rangkaian ke-5) nanti -- restrictOnDelete
     * supaya konsisten dengan "jangan hapus meja yang sudah dipakai di
     * transaksi" (pola sama dengan sales.member_id).
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('table_id')->nullable()->after('member_name_snapshot')
                ->constrained('tables')->restrictOnDelete();
            $table->string('table_name_snapshot')->nullable()->after('table_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('table_id');
            $table->dropColumn('table_name_snapshot');
        });
    }
};
