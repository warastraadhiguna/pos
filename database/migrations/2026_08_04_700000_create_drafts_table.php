<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Draft (nota belum final) yang sudah bisa disinkronkan lintas-device --
     * langkah 3/3 dari rangkaian fitur Draft (lihat langkah 1 dan 2, murni
     * lokal di SQLite HP). Server SEKARANG jadi sumber kebenaran bersama
     * untuk multi-pramusaji/multi-device, TAPI mobile tetap offline-first:
     * draft boleh dibuat/diedit offline, disinkronkan begitu online (pola
     * sama `sales.local_uuid`).
     *
     * `status` ENUM eksplisit (bukan soft-delete `deleted_at` bawaan
     * Eloquent) -- soft-delete Laravel pakai global scope yang otomatis
     * MENYEMBUNYIKAN baris finalized/cancelled dari SEMUA query termasuk
     * pull inkremental yang justru PERLU melihatnya (supaya bisa
     * memberitahu device lain "draft ini sudah selesai, hapus dari
     * daftar lokalmu") -- lihat DraftSyncService::pull().
     *
     * `held_by_user_id`/`held_by_device_label`/`held_at` -- soft-lock
     * "satu pemegang dalam satu waktu" (bukan merge edit-bersamaan
     * real-time). Lihat DraftSyncService::hold()/release() untuk mekanisme
     * lengkap (klaim atomik, timeout 5 menit, ambil-alih eksplisit).
     * `nullOnDelete` -- draft tidak boleh mencegah penghapusan akun user.
     *
     * `member_id`/`table_id` pola IDENTIK `sales` (lihat migrasi
     * `add_member_to_sales_table`/`add_table_to_sales_table`, yang
     * docblock-nya sendiri sudah menyiapkan ini "untuk dipakai ulang oleh
     * fitur draft") -- `*_name_snapshot` dibekukan saat terakhir disimpan,
     * `restrictOnDelete` supaya member/meja yang sedang dipakai draft aktif
     * tidak bisa dihapus permanen.
     */
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            // Disiapkan untuk multi-outlet (principle #7), selalu 1 sekarang.
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->uuid('local_uuid')->unique();
            $table->enum('status', ['open', 'finalized', 'cancelled'])->default('open');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->restrictOnDelete();
            $table->string('member_name_snapshot')->nullable();
            $table->foreignId('table_id')->nullable()->constrained('tables')->restrictOnDelete();
            $table->string('table_name_snapshot')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('held_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('held_by_device_label')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
