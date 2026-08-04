<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu baris item di dalam sebuah Draft -- mirror PERSIS bentuk
     * `draft_lines` lokal (SQLite HP, lihat `AppDatabase._onCreate`),
     * termasuk `is_printed` (langkah 2). `tax_rate` snapshot desimal
     * langsung (BUKAN `tax_rate_id` FK seperti `sale_lines`) -- draft
     * belum final, angka ini murni untuk subtotal yang kasir lihat SAAT
     * mengedit, dihitung ulang sungguhan oleh SaleService saat benar-benar
     * difinalisasi jadi Sale (lihat SaleController@store, tidak berubah
     * sama sekali oleh langkah ini).
     *
     * `local_uuid` (BARU, tidak ada di `sale_lines`) -- identitas STABIL
     * per baris, dibuat SEKALI di HP saat baris pertama kali ada dan
     * dipertahankan selama baris itu diedit di tempat (lihat
     * `CartItem.lineUuid`/`draft_mapper.dart`). Inilah yang membuat
     * resolusi konflik PER-ITEM mungkin (lihat DraftSyncService::push())
     * -- tanpa identitas stabil ini, server tidak bisa tahu "baris di
     * device A" dan "baris di device B" itu baris logis yang SAMA atau
     * BEDA, cuma bisa timpa-semua per draft (berisiko hilang data).
     *
     * `content_updated_at` (BARU) -- jam SAAT KONTEN baris ini (qty/
     * catatan/variasi/dihapus) terakhir BENAR-BENAR berubah, dicatat
     * EKSPLISIT oleh aplikasi (BUKAN kolom `updated_at` Eloquent otomatis,
     * yang bisa ter-touch oleh operasi lain yang tidak terkait konten,
     * mis. `save()` administratif) -- inilah pembanding "last-write-wins
     * per item" (lihat docblock DraftSyncService::mergeLine()). SENGAJA
     * TIDAK ikut naik saat `is_printed` berubah -- mencetak bukan
     * perubahan konten (lihat kolom `is_printed` di bawah).
     *
     * `is_deleted` (BARU) -- tombstone, BUKAN baris yang benar-benar
     * dihapus dari tabel. Baris yang dihapus kasir di satu device harus
     * tetap "ada" (ditandai) supaya device lain yang belum tarik data
     * tahu itu SENGAJA dihapus, bukan sekadar "belum pernah dengar soal
     * baris ini" -- lihat docblock DraftSyncService::mergeLine() untuk
     * kenapa ambiguitas ini berbahaya kalau tidak ditangani.
     *
     * `restrictOnDelete` ke `products` (pola sama `sale_lines`) --
     * `cascadeOnDelete` ke `drafts` (baris ikut hilang begitu draft
     * induknya benar-benar dihapus/kedaluwarsa, bukan cuma finalized/
     * cancelled yang tetap disimpan sebagai riwayat status).
     */
    public function up(): void
    {
        Schema::create('draft_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('drafts')->cascadeOnDelete();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('tax_rate', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->text('note')->nullable();
            $table->boolean('is_printed')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('content_updated_at');
            $table->timestamps();

            $table->index('draft_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_lines');
    }
};
