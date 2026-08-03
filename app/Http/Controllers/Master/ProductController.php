<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Services\ProductImageService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private readonly ProductImageService $images) {}

    public function index(): Response
    {
        return Inertia::render('Master/Products/Index', [
            'products' => Product::with(['taxRate', 'productCategory'])->withCount('components')->orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/Products/Form', [
            'product' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, null);

        $product = DB::transaction(function () use ($data) {
            $product = Product::create($data['product']);
            $product->components()->createMany($data['components']);
            $this->syncVariations($product, $data['variations']);

            return $product;
        });

        // Di LUAR transaction DB -- upload/resize menyentuh filesystem,
        // bukan database, dan gagal di sini seharusnya tidak membatalkan
        // produk yang sudah berhasil dibuat (kasir tetap bisa jual produk
        // ini tanpa gambar, admin tinggal coba upload ulang).
        if ($data['image'] !== null) {
            $this->images->store($product, $data['image']);
        }

        return Redirect::route('master.products.index')->with('success', 'Produk berhasil ditambahkan.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Master/Products/Form', [
            'product' => $product->load([
                'components.item.baseUom:id,code',
                'variations.components.item.baseUom:id,code',
            ]),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateData($request, $product);

        DB::transaction(function () use ($data, $product) {
            $product->update($data['product']);
            // BOM diganti seluruhnya setiap update — lebih sederhana & aman
            // daripada diff baris-per-baris untuk CRUD admin yang jarang dipakai.
            // Variasi TIDAK bisa memakai pola yang sama -- lihat
            // syncVariations() di bawah: sale_line_variations.variation_id
            // restrictOnDelete berarti "hapus semua lalu buat ulang" akan
            // gagal begitu ada variasi yang pernah terjual.
            $product->components()->delete();
            $product->components()->createMany($data['components']);
            $this->syncVariations($product, $data['variations']);
        });

        // Di LUAR transaction DB, sama alasannya dengan store() -- lihat
        // komentar di sana. `remove_image` diperiksa duluan: mengganti DAN
        // menghapus dalam satu submit sekaligus tidak masuk akal, tapi
        // kalau keduanya somehow terkirim, ganti gambar (aksi yang lebih
        // eksplisit/disengaja) menang.
        if ($data['image'] !== null) {
            $this->images->store($product, $data['image']);
        } elseif ($data['remove_image']) {
            $this->images->remove($product);
        }

        return Redirect::route('master.products.index')->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        return $this->deleteOrFail($product, 'master.products.index', 'Produk');
    }

    /**
     * @return array{product: array, components: array<int, array>, image: \Illuminate\Http\UploadedFile|null, remove_image: bool}
     */
    private function validateData(Request $request, ?Product $product): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')->ignore($product?->id),
            ],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'tax_rate_id' => ['nullable', 'exists:tax_rates,id'],
            'product_category_id' => ['nullable', 'exists:product_categories,id'],
            'is_active' => ['boolean'],
            'components' => ['array'],
            'components.*.item_id' => ['required', 'exists:items,id'],
            'components.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'components.*.uom_id' => ['required', 'exists:uoms,id'],
            // Variasi Berbayar (Tahap 1, harga-saja) -- `id` null/absen
            // berarti baris baru; `id` terisi berarti baris yang sudah ada
            // (diedit atau dipertahankan apa adanya), lihat syncVariations().
            'variations' => ['array'],
            'variations.*.id' => ['nullable', 'integer'],
            'variations.*.name' => ['required', 'string', 'max:255'],
            'variations.*.additional_price' => ['required', 'numeric', 'min:0'],
            'variations.*.is_active' => ['boolean'],
            // BOM per variasi (Tahap 2) -- kosong berarti variasi ini tidak
            // konsumsi apa pun, HPP-nya 0 (identik Tahap 1). Sama aturan
            // dengan `components.*` di atas (BOM produk).
            'variations.*.components' => ['array'],
            'variations.*.components.*.item_id' => ['required', 'exists:items,id'],
            'variations.*.components.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'variations.*.components.*.uom_id' => ['required', 'exists:uoms,id'],
            // max:5120 KB (5MB). dimensions max_width/max_height mencegah
            // "decompression bomb" -- file kecil tapi dimensi piksel raksasa
            // yang bisa memakan banyak memori saat diproses GD, terlepas
            // dari ukuran file di disk.
            'image' => [
                'nullable',
                'image',
                'mimes:jpeg,png,webp',
                'max:5120',
                'dimensions:max_width=8000,max_height=8000',
            ],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        return [
            'product' => [
                'name' => $validated['name'],
                'barcode' => $validated['barcode'] ?: null,
                'sell_price' => $validated['sell_price'],
                'tax_rate_id' => $validated['tax_rate_id'] ?? null,
                'product_category_id' => $validated['product_category_id'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ],
            'components' => $validated['components'] ?? [],
            'variations' => $validated['variations'] ?? [],
            'image' => $validated['image'] ?? null,
            'remove_image' => $request->boolean('remove_image'),
        ];
    }

    /**
     * Diff-based upsert -- BEDA dari components() yang blind-replace (lihat
     * komentar di update()): variasi yang sudah pernah dipakai transaksi
     * TIDAK BISA dihapus (sale_line_variations.variation_id
     * restrictOnDelete), jadi "hapus semua lalu buat ulang" akan
     * melemparkan QueryException tengah jalan. Sebagai gantinya:
     * - baris dengan `id` yang cocok dengan variasi yang sudah ada -> update.
     * - baris tanpa `id` -> buat baru.
     * - variasi lama yang TIDAK lagi ada di daftar submit -> coba hapus;
     *   kalau dibentur constraint (pernah terjual), nonaktifkan saja
     *   (is_active=false) -- setara "hapus" dari sudut pandang admin
     *   (tidak lagi bisa dipilih di kasir), tanpa merusak riwayat
     *   transaksi lama maupun menggagalkan seluruh penyimpanan produk.
     *
     * BOM per variasi (Tahap 2, `components`) sebaliknya BOLEH blind
     * delete+recreate seperti BOM produk di update() -- tabel
     * `product_variation_components` tidak direferensikan balik oleh apa
     * pun (lihat docblock migrasinya), jadi tidak kena constraint yang
     * memaksa variasi itu sendiri pakai diff-based upsert di atas.
     *
     * @param  array<int, array{id?: ?int, name: string, additional_price: int|float|string, is_active?: bool, components?: array<int, array{item_id: int, qty: int|float|string, uom_id: int}>}>  $variations
     */
    private function syncVariations(Product $product, array $variations): void
    {
        $existingIds = $product->variations()->pluck('id')->all();
        $submittedIds = array_filter(array_column($variations, 'id'));
        $toRemove = array_diff($existingIds, $submittedIds);

        foreach ($toRemove as $id) {
            $variation = ProductVariation::find($id);
            if (! $variation) {
                continue;
            }
            try {
                $variation->delete();
            } catch (QueryException) {
                $variation->update(['is_active' => false]);
            }
        }

        foreach ($variations as $variationData) {
            $payload = [
                'name' => $variationData['name'],
                'additional_price' => $variationData['additional_price'],
                'is_active' => (bool) ($variationData['is_active'] ?? true),
            ];

            if (! empty($variationData['id'])) {
                $variation = ProductVariation::where('id', $variationData['id'])
                    ->where('product_id', $product->id)
                    ->firstOrFail();
                $variation->update($payload);
            } else {
                $variation = $product->variations()->create($payload);
            }

            $variation->components()->delete();
            $variation->components()->createMany($variationData['components'] ?? []);
        }
    }

    /**
     * Item is deliberately not listed here — the item picker on the form
     * searches on demand (Master\ItemController::search()) instead of the
     * page shipping the entire catalog up front.
     *
     * @return array{uoms: \Illuminate\Support\Collection, taxRates: \Illuminate\Support\Collection, productCategories: \Illuminate\Support\Collection, variationEnabled: bool}
     */
    private function formOptions(): array
    {
        return [
            'uoms' => Uom::orderBy('code')->get(),
            'taxRates' => TaxRate::orderBy('name')->get(),
            'productCategories' => ProductCategory::orderBy('name')->get(),
            // Penajaman UX "akses pengelolaan fitur opsional" -- variasi
            // dikelola EMBEDDED di form ini (bukan halaman terpisah), jadi
            // gerbangnya di sini, bukan di sidebar. OFF -> Form.jsx
            // menyembunyikan seluruh editor variasi (data yang sudah ada
            // TIDAK terhapus, cuma editornya disembunyikan -- muncul lagi
            // begitu saklarnya dinyalakan ulang di Pengaturan).
            'variationEnabled' => CompanySetting::current()->variation_enabled,
        ];
    }
}
