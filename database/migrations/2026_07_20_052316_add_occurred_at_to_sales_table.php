<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Momen transaksi SEBENARNYA (WIB), terpisah dari `date` (hari
            // kalender WIB, dipakai semua laporan -- TIDAK diubah) dan dari
            // `created_at` (waktu server MENERIMA baris ini, yang untuk sale
            // mobile offline-first bisa jauh berbeda dari waktu transaksi
            // sungguhan). Nullable + ditambahkan sesudah `date` supaya baris
            // lama tetap valid tanpa backfill -- tampilan sisi frontend
            // fallback ke `date` saja saat ini null.
            $table->dateTime('occurred_at')->nullable()->after('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('occurred_at');
        });
    }
};
