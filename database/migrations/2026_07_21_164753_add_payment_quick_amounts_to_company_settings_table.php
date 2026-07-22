<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Daftar nominal (array angka), bukan nilai tunggal -- JSON,
            // bukan string terpisah koma, supaya urutan & tipe (integer)
            // terjaga tanpa parsing manual di setiap pemakai. Default 5
            // nominal yang wajar untuk transaksi tunai kecil-menengah.
            // string() (bukan text()) -- MySQL tidak izinkan DEFAULT pada
            // kolom TEXT/BLOB/JSON (sudah pernah kena ini di
            // receipt_footer); string dengan maks 8 nominal tetap jauh di
            // bawah batas VARCHAR(255). Laravel 'array' cast tetap
            // encode/decode JSON otomatis dari kolom string biasa.
            $table->string('payment_quick_amounts')
                ->nullable()
                ->default('[5000,10000,20000,50000,100000]')
                ->after('show_product_image');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('payment_quick_amounts');
        });
    }
};
