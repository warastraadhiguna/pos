<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_payments', function (Blueprint $table) {
            $table->string('cash_account_code', 20)->default('1-1000')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('expense_payments', function (Blueprint $table) {
            $table->dropColumn('cash_account_code');
        });
    }
};
