<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Identitas toko -- menggantikan placeholder yang sebelumnya
            // hardcode di mobile (app_settings.store_name/address/phone,
            // nilai tetap 'WAnPOS'/'Alamat belum diatur'/'-'). Nullable:
            // toko boleh belum mengisi ini, tampilan (struk) yang
            // menentukan fallback-nya, bukan skema.
            $table->string('store_name')->nullable()->after('product_display_mode');
            $table->string('store_address')->nullable()->after('store_name');
            $table->string('store_phone')->nullable()->after('store_address');
            // string (bukan text) -- MySQL tidak izinkan DEFAULT di kolom
            // TEXT, dan footer struk memang selalu pendek (satu baris).
            $table->string('receipt_footer')->nullable()->default('Terima kasih atas kunjungan Anda')->after('store_phone');
            // Default true -- info stok di tombol kasir berguna langsung
            // begitu ada datanya, beda dari show_product_image (default
            // false, sengaja mati karena BELUM ada fitur upload gambar
            // sama sekali; menyalakan toggle ini sekarang tidak akan
            // menampilkan apa-apa selain tempat kosong).
            $table->boolean('show_stock_on_button')->default(true)->after('receipt_footer');
            $table->boolean('show_product_image')->default(false)->after('show_stock_on_button');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'store_name',
                'store_address',
                'store_phone',
                'receipt_footer',
                'show_stock_on_button',
                'show_product_image',
            ]);
        });
    }
};
