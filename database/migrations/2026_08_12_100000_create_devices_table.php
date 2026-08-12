<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device Binding -- satu baris per perangkat mobile yang pernah mencoba
     * login (lihat AuthController::login()). `device_id` adalah Android ID
     * (Settings.Secure.ANDROID_ID) yang dibaca sekali oleh app dan dikirim
     * setiap login -- stabil lintas restart/update app/reinstall (signing
     * key sama), tapi berubah kalau factory reset (disengaja: factory reset
     * = perangkat baru, wajar minta approval ulang lagi).
     *
     * `status`: pending (baru terdaftar, menunggu admin) -> approved (boleh
     * login & transaksi) -> revoked (dicabut, tidak bisa dipakai lagi).
     * Tidak ada jalur balik otomatis dari revoked -- admin harus approve
     * lagi secara eksplisit kalau ingin mengembalikan akses.
     *
     * `outlet_id` nullable & tidak di-enforce di logika manapun sekarang
     * (selalu 1 outlet, lihat docs/ROADMAP.md) -- disiapkan supaya nanti
     * device bisa diikat ke outlet tertentu tanpa migrasi struktur baru,
     * konsisten prinsip #7 PRINCIPLES.md.
     *
     * `registered_by_user_id` murni audit (siapa yang login pertama kali
     * dari device ini, memicu pendaftaran) -- BUKAN kepemilikan; device
     * yang sudah approved boleh dipakai login user manapun (device dipakai
     * bergantian antar shift kasir), lihat AuthController.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique();
            $table->string('label')->nullable();
            $table->enum('status', ['pending', 'approved', 'revoked'])->default('pending');
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
