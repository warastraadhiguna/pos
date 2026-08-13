<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Cabang Lapisan 1 -- lihat rancangan yang disetujui. `Outlet`
     * yang sudah ada sejak awal proyek ini (disiapkan PRINCIPLES.md prinsip
     * #7 untuk "multi-outlet nanti") DIPERLUAS menjadi entitas Cabang
     * penuh, BUKAN tabel `branches` baru -- menghindari dua konsep lokasi
     * paralel di skema.
     *
     * `is_headquarters` -- SATU-SATUNYA outlet yang jadi tempat pembelian
     * masuk (Lapisan 2). Keunikan ("cuma boleh satu pusat") DISENGAJA tidak
     * dijadikan constraint DB (partial unique index tidak dipakai di mana
     * pun di proyek ini) -- ditegakkan di `BranchService::setHeadquarters()`
     * (unflag semua lalu flag satu, dalam satu DB::transaction()).
     *
     * Kompatibilitas data lama (WAJIB, lihat gerbang konfirmasi
     * PRINCIPLES.md): outlet yang SUDAH ADA (di-seed FoundationSeeder,
     * direferensikan oleh sales/drafts/expenses/dst yang sudah punya
     * data) di-backfill jadi headquarters + aktif + kode 'PUSAT' di bawah
     * -- BUKAN dibiarkan tanpa status, supaya tidak ada transaksi lama
     * yang tiba-tiba "menunjuk ke cabang yang tidak jelas statusnya".
     * Hanya outlet PERTAMA (by id) yang ditandai headquarters, kalau
     * (secara hipotetis) ada lebih dari satu baris outlet sudah ada --
     * menjaga invarian "maksimal satu pusat" tetap benar bahkan di
     * migrasi ini sendiri.
     *
     * Pakai `DB::table()` (bukan model `Outlet` Eloquent) SENGAJA --
     * `Outlet` di titik migrasi ini dijalankan mungkin belum diperbarui
     * fillable-nya (model berubah seiring waktu, migrasi tidak) dan
     * mass-assignment lewat `update()` Eloquent akan diam-diam MENOLAK
     * kolom yang tidak fillable tanpa error apa pun -- persis jenis bug
     * yang nyaris lolos di migrasi ini sendiri saat pertama ditulis.
     * Query builder mentah tidak punya masalah itu.
     */
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('name');
            $table->string('address')->nullable()->after('code');
            $table->boolean('is_active')->default(true)->after('address');
            $table->boolean('is_headquarters')->default(false)->after('is_active');
        });

        DB::table('outlets')->update(['is_active' => true]);

        $firstId = DB::table('outlets')->orderBy('id')->value('id');
        if ($firstId !== null) {
            DB::table('outlets')->where('id', $firstId)->update([
                'is_headquarters' => true,
                'code' => 'PUSAT',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['code', 'address', 'is_active', 'is_headquarters']);
        });
    }
};
