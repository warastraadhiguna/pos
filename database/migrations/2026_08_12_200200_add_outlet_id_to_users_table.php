<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Cabang Lapisan 1 -- resolusi "cabang aktif" untuk kasir WEB
     * (`/kasir`, sesi browser, tidak ada konsep device fisik seperti
     * mobile). NULL = tidak ditugaskan ke satu cabang tertentu (admin/
     * manajer yang mengawasi banyak cabang, ATAU -- untuk SEMUA user yang
     * sudah ada hari ini -- "belum pernah diatur", diperlakukan sama
     * seperti sekarang: satu-satunya outlet yang ada).
     *
     * Bersama `devices.outlet_id` (sudah ada sejak Device Binding) sebagai
     * sumber utama untuk mobile, `users.outlet_id` ini jadi cadangan/jalur
     * web -- resolusi lengkapnya baru terjadi di Lapisan 3 (belum ada kode
     * yang membaca kolom ini di Lapisan 1).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('role_id')->constrained('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
