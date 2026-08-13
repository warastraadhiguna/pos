<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role Developer (hidden super-admin, lihat rancangan yang disetujui)
     * -- murni schema, tidak menyentuh data. Baris data (role Developer
     * itu sendiri) diseed di migrasi berikutnya.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_developer')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_developer');
        });
    }
};
