<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fitur Konsinyasi (titip jual) -- lihat rancangan yang disetujui.
     * `is_consignment` default false dan `consignment_supplier_id` default
     * null untuk SEMUA baris yang sudah ada -- nol perubahan perilaku untuk
     * item yang sudah terpasang, sampai admin sengaja menandainya lewat
     * form Item.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_consignment')->default(false)->after('costing_type');
            $table->foreignId('consignment_supplier_id')->nullable()->after('is_consignment')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consignment_supplier_id');
            $table->dropColumn('is_consignment');
        });
    }
};
