<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aligns the status vocabulary with how PurchaseService actually drives
     * it: 'open' (just created, nothing received yet), 'partial' (some qty
     * received), 'received' (fully received). No rows exist yet at the time
     * this was introduced, so the rename is a straight MODIFY.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('open', 'partial', 'received', 'cancelled') NOT NULL DEFAULT 'open'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('draft', 'submitted', 'partially_received', 'completed', 'cancelled') NOT NULL DEFAULT 'draft'");
    }
};
