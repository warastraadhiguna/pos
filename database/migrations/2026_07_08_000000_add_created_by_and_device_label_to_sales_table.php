<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Siapa/perangkat mana yang membuat sale ini. Keduanya nullable:
            // sale lama tidak punya nilai ini, dan sale web (bukan mobile)
            // tidak punya device. Murni untuk audit/debug sinkronisasi
            // multi-device — tidak dipakai oleh logika akuntansi/stok apa pun.
            $table->foreignId('created_by_user_id')->nullable()->after('warehouse_id')->constrained('users')->nullOnDelete();
            $table->string('device_label')->nullable()->after('local_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('device_label');
        });
    }
};
