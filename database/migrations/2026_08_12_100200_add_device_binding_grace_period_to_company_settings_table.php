<?php

use App\Models\CompanySetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    /**
     * Jendela waktu setelah fitur Device Binding aktif di mana device BARU
     * (device_id belum pernah tercatat) langsung auto-approved, bukan masuk
     * status pending -- jawaban untuk "jangan sampai fitur ini tiba-tiba
     * memblokir HP/tablet yang sudah jalan" (lihat rancangan yang
     * disetujui). Karena device_id memang belum pernah ada sebelum fitur
     * ini, tidak ada data historis untuk dicocokkan -- device existing
     * "diakui" murni lewat login PERTAMA mereka dengan APK baru yang jatuh
     * di dalam jendela ini, bukan lewat backfill data.
     *
     * NULL berarti grace period MATI (device baru selalu masuk pending,
     * perilaku normal jangka panjang) -- admin bisa memperpanjang,
     * mempersingkat, atau mematikannya kapan saja lewat halaman Pengaturan
     * (lihat SettingController::updateDeviceBindingGracePeriod()).
     *
     * Nilai awal: now() + 14 hari, supaya rollout APK ke device yang sudah
     * dipakai sekarang punya waktu wajar tanpa aksi admin manual sama
     * sekali. 14 hari dipilih sebagai default yang aman untuk deployment
     * kecil (device dikontrol langsung oleh pemilik toko) -- bisa diubah
     * admin kapan saja setelahnya kalau rollout ternyata lebih lambat.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->timestamp('device_binding_grace_period_ends_at')->nullable()->after('qris_cash_account_code');
        });

        CompanySetting::query()->update([
            'device_binding_grace_period_ends_at' => Carbon::now()->addDays(14),
        ]);
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('device_binding_grace_period_ends_at');
        });
    }
};
