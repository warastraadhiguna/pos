<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor Meja -- fitur ke-2 dari rangkaian besar (sebelumnya: member;
     * berikutnya: catatan, variasi, draft). Sengaja TIDAK ada kolom status
     * (terisi/kosong) di sini -- keputusan itu belum diambil (bisa jadi
     * kolom di sini nanti, atau dihitung dari transaksi draft aktif, lihat
     * catatan di app/Models/DiningTable.php) -- migrasi additive nanti
     * cukup menambah apa pun yang diputuskan, tidak perlu membongkar tabel
     * ini.
     */
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('area')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
