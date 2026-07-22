<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // String biasa (bukan DB enum), sama seperti sales.payment_method
            // di tempat lain -- 'all' (grid penuh) | 'search_only' (grid
            // kosong sampai user mengetik). Default 'all' supaya baris lama
            // & instalasi yang belum sempat mengatur ini tidak berubah
            // perilakunya sama sekali.
            $table->string('product_display_mode', 20)->default('all')->after('ppn_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('product_display_mode');
        });
    }
};
