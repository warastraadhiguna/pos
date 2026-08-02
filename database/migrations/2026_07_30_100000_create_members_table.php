<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Member/pelanggan -- fitur pertama dari rangkaian besar (berikutnya:
     * meja, catatan, variasi, draft). Sengaja TIDAK ada kolom `points` di
     * sini -- kalau/saat fitur poin dibangun, itu sebaiknya jadi tabel
     * ledger terpisah append-only (mis. `member_point_transactions`),
     * konsisten dengan disiplin `stock_movements`/`journal_lines` di
     * codebase ini (angka berjalan yang bisa diaudit, bukan kolom mutable
     * yang gampang balapan) -- migrasi additive nanti cukup menambah tabel
     * baru + FK ke sini, tidak perlu membongkar apa pun.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
