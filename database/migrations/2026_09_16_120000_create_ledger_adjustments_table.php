<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anchor polymorphic utk jurnal koreksi/konsolidasi manual yang
        // TIDAK punya dokumen sumber asli (Sale/GoodsReceipt/dst) -- mis.
        // memadatkan riwayat transaksi lama yang dihapus jadi satu entri
        // saldo awal, supaya total per akun (Kas/Bank/Penjualan/dst) tetap
        // sama. Lihat App\Console\Commands\PurgeTestSalesBeforeDate.
        Schema::create('ledger_adjustments', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_adjustments');
    }
};
