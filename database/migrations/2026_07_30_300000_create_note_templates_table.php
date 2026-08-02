<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan + Template -- fitur ketiga dari rangkaian besar (setelah
     * member, meja; berikutnya: variasi, draft). BEDA dari members/tables:
     * template di sini murni "isi cepat yang bisa diedit", bukan entitas
     * yang direferensikan lewat FK -- lihat docblock `sales.note`/
     * `sale_lines.note`, keduanya TIDAK punya kolom `note_template_id`.
     * Karena tidak direferensikan sama sekali, tidak perlu
     * restrictOnDelete seperti members/tables -- admin boleh menonaktifkan
     * (pola master ringan) ATAU menghapus permanen, keduanya aman.
     */
    public function up(): void
    {
        Schema::create('note_templates', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_templates');
    }
};
