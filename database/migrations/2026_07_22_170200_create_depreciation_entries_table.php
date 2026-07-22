<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            // Format 'YYYY-MM' -- periode yang diwakili entri ini (bukan
            // tanggal diproses). Unique bareng fixed_asset_id: jaminan di
            // level DATABASE (bukan cuma aplikasi) supaya satu aset tidak
            // pernah punya dua entri penyusutan untuk periode yang sama --
            // sama seperti sales.local_uuid, aplikasi mengecek dulu untuk
            // UX yang ramah (lewati diam-diam), constraint ini jaring
            // pengaman terakhir kalau ada race condition.
            $table->string('period', 7);
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
    }
};
