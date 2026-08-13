<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Cabang Lapisan 1 -- kas per-cabang (rancangan yang disetujui,
     * poin 5). NULL (default, dan nilai SEMUA akun yang sudah ada hari
     * ini) berarti akun global/bersama (Penjualan, HPP, PPN, Kas default
     * `1-1000`, dst) -- TIDAK BERUBAH SAMA SEKALI. Diisi berarti akun itu
     * representasi kas/bank milik satu cabang tertentu.
     *
     * Lapisan 1 CUMA menambah kolom + memperbolehkan admin menandai akun
     * Bank baru sebagai milik cabang tertentu lewat form yang sudah ada
     * (`CashAccountService::createBankAccount()`) -- belum membuat akun
     * kas per-cabang otomatis, belum meng-enforce "transaksi cabang A
     * wajib masuk Kas cabang A" (itu Lapisan 2/3, begitu penjualan
     * sungguhan ter-tag cabang).
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('parent_id')->constrained('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
