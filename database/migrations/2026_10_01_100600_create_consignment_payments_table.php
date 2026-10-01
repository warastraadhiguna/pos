<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pelunasan Hutang Konsinyasi -- struktur PERSIS supplier_payments,
     * sengaja tabel terpisah (bukan reuse) supaya subsistem Hutang Usaha
     * yang sudah ada tidak tersentuh sama sekali (lihat rancangan).
     */
    public function up(): void
    {
        Schema::create('consignment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->string('cash_account_code', 20);
            $table->string('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_payments');
    }
};
