<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menautkan token Sanctum mobile ke device yang menerbitkannya, murni
     * supaya DeviceService::revoke() bisa langsung menghapus token yang
     * masih aktif milik device itu saat admin mencabut aksesnya (instan,
     * kalau device online) -- lapis pertama dari dua lapis penegakan revoke
     * (lapis kedua: cek berkala + tenggang 7 hari di sisi mobile, untuk
     * kasus device sedang offline saat dicabut). Nullable + tidak
     * berelasi FK (personal_access_tokens dipakai Sanctum untuk banyak
     * jenis tokenable, dan device bisa dihapus tanpa memaksa token ikut
     * hilang) -- token lama sebelum fitur ini tetap punya nilai NULL di
     * sini dan tetap berfungsi seperti biasa (tidak diblokir).
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_id')->nullable()->after('tokenable_id');
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });
    }
};
