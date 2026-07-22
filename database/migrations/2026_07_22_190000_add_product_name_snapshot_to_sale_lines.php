<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot nama produk di sale_lines -- unit_price/line_total SUDAH
     * snapshot sejak awal (disimpan langsung, tidak pernah dihitung ulang
     * dari relasi produk), tapi product_name ketinggalan sehingga rename
     * produk mengubah tampilan struk lama. Baris LAMA (product_name masih
     * NULL) langsung dibackfill dari nama produk saat ini pada migrasi yang
     * sama, membekukan nama sekarang supaya tidak terus mengikuti rename ke
     * depannya -- lebih baik daripada membiarkan NULL selamanya, karena itu
     * berarti baris lama akan terus butuh fallback ke nama produk terkini
     * setiap kali ditampilkan, persis bug yang sedang diperbaiki. Nama pada
     * momen transaksi ASLI tidak bisa dipulihkan kalau produk itu sudah
     * di-rename SEBELUM migrasi ini berjalan (data itu memang belum pernah
     * tersimpan) -- backfill hanya membekukan titik terbaik yang tersedia
     * sekarang, mencegah drift lebih lanjut.
     */
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
        });

        DB::statement(<<<'SQL'
            UPDATE sale_lines
            JOIN products ON products.id = sale_lines.product_id
            SET sale_lines.product_name = products.name
            WHERE sale_lines.product_name IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('product_name');
        });
    }
};
