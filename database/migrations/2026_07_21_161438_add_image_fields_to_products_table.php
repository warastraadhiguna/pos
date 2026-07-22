<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Path RELATIF di disk `public` (bukan URL absolut -- itu
            // diturunkan lewat accessor Product::imageUrl()/imageUrlWeb()
            // saat dibutuhkan, supaya APP_URL boleh berubah tanpa migrasi
            // ulang data). Thumbnail (~200px persegi) dipakai di MANA PUN
            // tombol kasir tampil (web & mobile) -- satu-satunya yang
            // pernah ikut sync ke mobile. Versi web (~600px) HANYA dipakai
            // halaman admin (Master/Products), tidak pernah dikirim ke
            // endpoint sync mobile.
            $table->string('image_path')->nullable()->after('barcode');
            $table->string('image_path_web')->nullable()->after('image_path');

            // Token acak per-upload (BUKAN hash konten) -- disisipkan ke
            // nama file tersimpan (mis. thumb_{hash}.jpg), jadi setiap
            // ganti gambar otomatis menghasilkan URL BARU. Ini yang membuat
            // invalidasi cache di HP/browser gratis: tidak perlu kode
            // invalidasi eksplisit sama sekali, URL lama cukup tidak pernah
            // diminta lagi begitu produk disync ulang dengan URL barunya.
            $table->string('image_hash', 16)->nullable()->after('image_path_web');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_path_web', 'image_hash']);
        });
    }
};
