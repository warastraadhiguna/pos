<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menandai permission yang TIDAK BOLEH pernah tampil sebagai checkbox
     * yang bisa dicentang di UI Kelola Role (RoleController::permissionGroups()),
     * untuk role APA PUN (Admin/Manajer/Kasir/custom) -- bukan cuma
     * "disembunyikan dari role Developer", tapi "tidak pernah bisa
     * di-attach ke role manapun lewat UI sama sekali". Ini yang menutup
     * celah admin memberi permission developer-only ke role Admin miliknya
     * sendiri lewat Kelola Role, terlepas dari role Developer disembunyikan
     * atau tidak.
     */
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->boolean('is_developer_only')->default(false)->after('group');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('is_developer_only');
        });
    }
};
