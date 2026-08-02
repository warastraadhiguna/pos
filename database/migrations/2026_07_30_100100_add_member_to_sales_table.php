<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `member_name_snapshot` dibekukan SAAT transaksi (sama prinsipnya
     * dengan `sale_lines.product_name`) -- rename/nonaktifkan member tidak
     * boleh mengubah nota lama. `member_id` disimpan TERPISAH, khusus
     * untuk riwayat per-member nanti dan sebagai jalur yang akan dipakai
     * ulang oleh fitur draft (rangkaian ke-5) -- restrictOnDelete supaya
     * konsisten dengan "jangan hapus member yang sudah dipakai di
     * transaksi" (pola sama dengan sale_lines.product_id).
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('created_by_user_id')
                ->constrained('members')->restrictOnDelete();
            $table->string('member_name_snapshot')->nullable()->after('member_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn('member_name_snapshot');
        });
    }
};
