<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs PostingService's journal number generation. A row always
     * exists for a period once it's been touched once (via an atomic
     * upsert), so lockForUpdate() always has something concrete to lock —
     * even for the very first journal of a brand new month — removing any
     * dependency on gap-lock behavior for an empty LIKE-prefix range.
     */
    public function up(): void
    {
        Schema::create('journal_number_sequences', function (Blueprint $table) {
            $table->string('period', 20)->primary();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_number_sequences');
    }
};
