<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anchor polymorphic (pola sama LedgerAdjustment/EquityTransaction)
        // utk jurnal & stock_movements PEMBALIK saat sale dibatalkan --
        // lihat App\Services\SaleService::voidSale(). Satu baris di sini =
        // satu tindakan void, jadi juga jadi penanda idempoten (cek exists()
        // by sale_id sebelum boleh void lagi) sekaligus jejak audit
        // (siapa, kapan, kenapa) -- sale asli & jurnal aslinya TIDAK PERNAH
        // disentuh/dihapus.
        Schema::create('sale_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_voids');
    }
};
