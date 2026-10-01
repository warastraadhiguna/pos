<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Nota" sumber kebenaran per-supplier untuk Hutang Konsinyasi -- satu
     * baris per (Sale, supplier) setiap kali Sale itu menjual item
     * konsinyasi, diisi SaleService dalam transaksi DB yang SAMA dengan
     * posting jurnal Sale (lihat rancangan).
     *
     * Dibutuhkan karena journal_lines (lihat migrasinya) tidak punya
     * dimensi supplier sama sekali -- satu Sale yang mencampur 2 supplier
     * konsinyasi berbeda akan menghasilkan 2 baris kredit ke akun 2-3000
     * yang SAMA, tidak bisa dibedakan lagi lewat journal_lines saja. Tabel
     * ini yang jadi "nota" per-supplier, pola sama GoodsReceipt berperan
     * untuk Hutang Usaha (2-1000).
     *
     * `voided_at` diisi SaleService::voidSale() saat Sale sumbernya
     * dibatalkan -- laporan Hutang Konsinyasi memfilter `whereNull('voided_at')`.
     */
    public function up(): void
    {
        Schema::create('consignment_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_accruals');
    }
};
