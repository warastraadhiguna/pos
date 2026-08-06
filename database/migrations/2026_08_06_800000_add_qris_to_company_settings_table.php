<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metode pembayaran QRIS (Tafsir A -- pencatatan, BUKAN integrasi
     * payment gateway; lihat rancangan fitur ini). `qris_enabled` -- pola
     * identik member_enabled/table_enabled/note_enabled/variation_enabled/
     * draft_enabled: default FALSE, OFF berarti opsi QRIS tidak muncul sama
     * sekali di kasir manapun (web/mobile).
     *
     * `qris_cash_account_code` -- akun Bank TUJUAN uang QRIS (lihat
     * CashAccountService), diisi WAJIB saat `qris_enabled` diaktifkan
     * (divalidasi di SettingController::updateQris(), bukan di level DB --
     * kolom ini sengaja nullable supaya toko yang belum pernah mengaktifkan
     * QRIS tidak perlu punya nilai apa pun di sini). TIDAK PERNAH boleh
     * kode akun Kas (CashAccountService::DEFAULT_CODE) -- uang QRIS SELALU
     * mendarat di rekening bank, bukan laci tunai, itulah inti fitur ini
     * (lihat SaleService::createSale(), InvalidQrisAccountException).
     * `string` (bukan foreign key ke `accounts`) -- pola identik
     * `sales.cash_account_code`/`expenses.cash_account_code` dkk, kode akun
     * dipakai apa adanya di seluruh sistem ini, bukan id.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('qris_enabled')->default(false)->after('draft_enabled');
            $table->string('qris_cash_account_code', 20)->nullable()->after('qris_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['qris_enabled', 'qris_cash_account_code']);
        });
    }
};
