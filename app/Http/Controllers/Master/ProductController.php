<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Services\ProductImageService;
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
            'product' => $product->load('components.item.baseUom:id,code'),
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
            $product->components()->delete();
            $product->components()->createMany($data['components']);
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
            'image' => $validated['image'] ?? null,
            'remove_image' => $request->boolean('remove_image'),
        ];
    }

    /**
     * Item is deliberately not listed here — the item picker on the form
     * searches on demand (Master\ItemController::search()) instead of the
     * page shipping the entire catalog up front.
     *
     * @return array{uoms: \Illuminate\Support\Collection, taxRates: \Illuminate\Support\Collection, productCategories: \Illuminate\Support\Collection}
     */
    private function formOptions(): array
    {
        return [
            'uoms' => Uom::orderBy('code')->get(),
            'taxRates' => TaxRate::orderBy('name')->get(),
            'productCategories' => ProductCategory::orderBy('name')->get(),
        ];
    }
}
