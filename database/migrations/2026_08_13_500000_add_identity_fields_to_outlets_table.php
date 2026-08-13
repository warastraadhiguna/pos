<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identitas per-cabang (header struk) -- lihat rancangan yang
     * disetujui. `name`/`address` (Multi-Cabang Lapisan 1) DIREUSE
     * langsung, cuma dua kolom yang belum ada: `phone` & `receipt_footer`.
     * Murni aditif & nullable -- kosong berarti "belum diisi", cabang
     * jatuh ke identitas `company_settings` global (lihat
     * BranchService::resolveReceiptIdentity()). Nol risiko data lama.
     */
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('address');
            $table->string('receipt_footer')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['phone', 'receipt_footer']);
        });
    }
};
