<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Cabang Lapisan 1 -- saklar global, pola identik member_enabled/
     * table_enabled/note_enabled/dst. Default FALSE (WAJIB): toko satu
     * lokasi tidak melihat menu "Kelola Cabang" atau pemilih cabang di
     * mana pun -- perilaku 100% sama seperti sebelum fitur ini ada. ON
     * murni menampilkan menu (lihat AuthenticatedLayout.jsx `feature:
     * 'multi_branch_enabled'`) -- TIDAK PERNAH jadi syarat otorisasi
     * (`permission:branches.manage` tetap menggerbangi route-nya sendiri).
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('multi_branch_enabled')->default(false)->after('device_binding_grace_period_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('multi_branch_enabled');
        });
    }
};
