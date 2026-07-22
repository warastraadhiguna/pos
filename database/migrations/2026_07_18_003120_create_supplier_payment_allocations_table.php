<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * goods_receipt_id is NULLABLE on purpose: a null-receipt allocation row
     * represents an amount not (yet) tied to a specific nota — an
     * advance/overpayment, or a legacy aggregate-model payment being
     * backfilled (see the next migration). This keeps ONE invariant true
     * for every payment, with no exceptions: SUM(allocations.amount) for a
     * supplier_payment always equals that payment's amount — enforced in
     * SupplierPaymentService::recordPayment(), not a DB constraint (MySQL
     * has no cross-table SUM CHECK).
     */
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
