<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purely descriptive metadata (e.g. "barang sedikit penyok", "kurir
     * bilang sisa menyusul minggu depan") — never read by any accounting
     * logic, journal, or stock calculation.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('grand_total');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
