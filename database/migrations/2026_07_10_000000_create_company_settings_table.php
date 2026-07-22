<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton (selalu satu baris, id=1) untuk saklar/pengaturan tingkat
     * sistem yang harus konsisten dibaca web & API — bukan atribut outlet
     * (PKP/non-PKP adalah status legal badan usaha, bukan lokasi/gudang)
     * dan bukan tabel key-value generik (menyimpang dari gaya kolom
     * bertipe eksplisit yang dipakai di seluruh skema ini).
     */
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('ppn_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
