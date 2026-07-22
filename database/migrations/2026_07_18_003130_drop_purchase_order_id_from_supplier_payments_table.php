<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * purchase_order_id was the old aggregate model's optional reference
     * note. It's superseded by supplier_payment_allocations (finer-grained:
     * per-GoodsReceipt, not per-PO, and supports many-to-many). Before
     * dropping it, backfill one allocation row per existing payment with
     * goods_receipt_id = NULL ("not tied to a specific nota") — we don't
     * know which specific GoodsReceipt an old payment applied to (the
     * aggregate model never recorded that), so we deliberately don't guess.
     * This keeps the SUM(allocations.amount) = payment.amount invariant
     * true retroactively without fabricating history. No existing
     * journals/journal_lines rows are touched — this only adds metadata.
     *
     * At the time this was written there are zero supplier_payments rows in
     * any environment this has shipped to, so the backfill is a no-op in
     * practice — it's here for correctness in case that's no longer true by
     * the time this runs.
     */
    public function up(): void
    {
        DB::table('supplier_payments')->orderBy('id')->each(function ($payment) {
            DB::table('supplier_payment_allocations')->insert([
                'supplier_payment_id' => $payment->id,
                'goods_receipt_id' => null,
                'amount' => $payment->amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained('purchase_orders')->restrictOnDelete();
        });
    }
};
