<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DEFAULT 'credit' is not a placeholder — it's an accurate historical
     * fact. Every goods receipt ever recorded before this column existed was
     * processed under the old code path, which always credited Hutang Usaha.
     * Backfilling existing rows to 'credit' via this default correctly
     * labels what already happened; it does not touch or reinterpret any
     * journal_lines row (see PurchaseService::postGoodsReceiptJournal()).
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('payment_method', 50)->default('credit')->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
