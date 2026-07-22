<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            // Disiapkan sekarang untuk multi-outlet, saat ini selalu bernilai 1.
            // Tidak perlu warehouse_id — ini transaksi keuangan murni, tidak
            // menyentuh stok.
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            // Referensi opsional ke PO tertentu — catatan, BUKAN alokasi wajib.
            // Pembayaran tetap dicatat agregat per supplier; lihat
            // SupplierPayableReportService untuk perhitungan sisa hutang.
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->string('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
