<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('name');
            // Teks bebas -- deskriptif saja (Peralatan/Kendaraan/dll), BUKAN
            // akun CoA terpisah. Lihat dokumen rancangan: satu akun Aset
            // Tetap untuk semua kategori, cukup untuk skala toko kecil.
            $table->string('category')->nullable();
            $table->date('purchase_date');
            $table->decimal('acquisition_cost', 18, 4);
            $table->decimal('residual_value', 18, 4)->default(0);
            $table->unsignedInteger('useful_life_months');
            // String biasa (bukan DB enum), sama seperti payment_method --
            // siap ditambah metode lain nanti tanpa migrasi baru. Cuma
            // 'straight_line' yang didukung sekarang.
            $table->string('depreciation_method', 50)->default('straight_line');
            $table->string('payment_method', 50)->default('cash');
            // Hanya relevan saat payment_method='cash' -- diabaikan
            // sepenuhnya untuk pembelian kredit (Cr Hutang Lain-lain).
            $table->string('cash_account_code', 20)->default('1-1000');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
