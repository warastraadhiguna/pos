<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Cabang Lapisan 2 -- lihat rancangan yang disetujui. Dokumen
     * distribusi stok pusat->cabang, pola PERSIS `purchase_orders`
     * (header = intent, eksekusi terpisah yang benar-benar menyentuh
     * ledger -- lihat `DistributionService`), bedanya dokumen ini punya
     * DUA dimensi warehouse (asal & tujuan) alih-alih satu.
     *
     * `source_warehouse_id` WAJIB warehouse milik outlet
     * `is_headquarters=true` -- ditegakkan `DistributionService`, BUKAN
     * constraint DB (konsisten pola `BranchService`/`is_headquarters`
     * Lapisan 1: validasi di Service layer, bukan constraint kaku).
     *
     * `status`: draft (dibuat, belum menyentuh stok sama sekali) ->
     * completed (sudah dieksekusi, DUA stock_movements tercipta) /
     * cancelled (dibatalkan sebelum dieksekusi, stok tidak pernah
     * tersentuh). TIDAK ada status "partial" -- satu dokumen = satu
     * eksekusi utuh (simplifikasi Lapisan 2 yang disetujui).
     *
     * `executed_by_user_id`/`executed_at` TERPISAH dari `created_by_user_id`
     * -- siapa MEMBUAT draft belum tentu sama dengan siapa yang MENGEKSEKUSI
     * transfer (mirror pola `registered_by_user_id` vs `approved_by_user_id`
     * di Device Binding).
     */
    public function up(): void
    {
        Schema::create('stock_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('dest_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('date');
            $table->enum('status', ['draft', 'completed', 'cancelled'])->default('draft');
            $table->string('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_distributions');
    }
};
