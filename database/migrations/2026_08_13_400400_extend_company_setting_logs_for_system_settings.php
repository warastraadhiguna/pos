<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role Developer (hidden super-admin, lihat rancangan yang disetujui,
     * poin audit) -- toggle Multi-Cabang & grace period Device Binding
     * naik jadi tindakan berakses-tinggi (developer-only), jadi sekarang
     * ikut dicatat ke `company_setting_logs` seperti PPN, bukan lagi
     * "sengaja tidak dicatat" seperti sebelumnya.
     *
     * `ppn_active` dilonggarkan jadi nullable (raw SQL -- ->change()
     * butuh doctrine/dbal yang belum jadi dependency di proyek ini) karena
     * sekarang satu baris cuma mengisi SATU kolom nilai sesuai
     * `setting_key`, sisanya null. Baris LAMA (sebelum migrasi ini) selalu
     * punya `ppn_active` terisi dan `setting_key` null -- kode pembaca
     * (SettingController::index()) memperlakukan `setting_key` null
     * sebagai 'ppn_active' untuk kompatibilitas mundur, jadi data lama
     * tetap valid & terbaca tanpa backfill.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE company_setting_logs MODIFY ppn_active TINYINT(1) NULL');

        Schema::table('company_setting_logs', function (Blueprint $table) {
            $table->string('setting_key')->nullable()->after('id');
            $table->boolean('multi_branch_enabled')->nullable()->after('ppn_active');
            $table->dateTime('device_binding_grace_period_ends_at')->nullable()->after('multi_branch_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_setting_logs', function (Blueprint $table) {
            $table->dropColumn(['setting_key', 'multi_branch_enabled', 'device_binding_grace_period_ends_at']);
        });

        DB::statement('ALTER TABLE company_setting_logs MODIFY ppn_active TINYINT(1) NOT NULL');
    }
};
