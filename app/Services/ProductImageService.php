<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * Satu-satunya tempat yang tahu cara menyimpan/mengganti/menghapus gambar
 * produk — resize+kompresi dilakukan SINKRON saat upload (bukan queue job:
 * proyek ini QUEUE_CONNECTION=sync, tidak ada worker queue sungguhan
 * berjalan, dan resize satu gambar hanya perlu puluhan-ratusan ms).
 *
 * Dua turunan disimpan per upload, TIDAK PERNAH file asli:
 * - thumbnail (~200px persegi, cover/crop-tengah) — SATU-SATUNYA yang
 *   pernah ikut sync ke mobile & dipakai tombol kasir (web maupun mobile).
 * - web (~600px, muat-dalam-kotak, tidak di-crop) — HANYA dipakai halaman
 *   admin (Master/Products) untuk pratinjau yang lebih jelas.
 *
 * Nama file menyisipkan token acak per-upload (`image_hash`, BUKAN hash
 * konten) — setiap ganti gambar otomatis menghasilkan path/URL baru, jadi
 * cache HP/browser tidak pernah perlu invalidasi eksplisit: URL lama
 * cukup tidak pernah diminta lagi begitu sync berikutnya membawa URL baru.
 */
class ProductImageService
{
    private const DISK = 'public';

    private const THUMB_SIZE = 200;

    private const WEB_MAX = 600;

    public function store(Product $product, UploadedFile $file): void
    {
        $this->deleteFiles($product);

        $hash = Str::random(12);
        $manager = ImageManager::gd();
        $source = $manager->read($file->getRealPath());

        $thumbPath = "products/{$product->id}/thumb_{$hash}.jpg";
        $webPath = "products/{$product->id}/web_{$hash}.jpg";

        // clone() dulu -- cover() memodifikasi instance in-place, dan kedua
        // turunan harus berasal dari gambar sumber yang SAMA (bukan yang
        // sudah di-crop persegi oleh thumbnail).
        $thumb = (clone $source)->cover(self::THUMB_SIZE, self::THUMB_SIZE);
        $web = $source->scaleDown(width: self::WEB_MAX, height: self::WEB_MAX);

        Storage::disk(self::DISK)->put($thumbPath, (string) $thumb->toJpeg(quality: 80));
        Storage::disk(self::DISK)->put($webPath, (string) $web->toJpeg(quality: 85));

        $product->forceFill([
            'image_path' => $thumbPath,
            'image_path_web' => $webPath,
            'image_hash' => $hash,
        ])->save();
    }

    public function remove(Product $product): void
    {
        $this->deleteFiles($product);

        $product->forceFill([
            'image_path' => null,
            'image_path_web' => null,
            'image_hash' => null,
        ])->save();
    }

    private function deleteFiles(Product $product): void
    {
        foreach ([$product->image_path, $product->image_path_web] as $path) {
            if ($path) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }
}
