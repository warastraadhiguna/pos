<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail untuk perubahan saklar `company_settings.ppn_active` lewat
     * UI admin — satu baris per perubahan NILAI (bukan tiap submit form),
     * dipertahankan meski user yang mengubah dihapus (nullOnDelete) supaya
     * jejak audit untuk keperluan pajak tidak pernah hilang.
     */
    public function up(): void
    {
        Schema::create('company_setting_logs', function (Blueprint $table) {
            $table->id();
            $table->boolean('ppn_active');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_setting_logs');
    }
};
