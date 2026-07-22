<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Master/ProductCategories/Index', [
            'productCategories' => ProductCategory::orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/ProductCategories/Form', [
            'productCategory' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        ProductCategory::create($data);

        return Redirect::route('master.product-categories.index')->with('success', 'Kategori produk berhasil ditambahkan.');
    }

    public function edit(ProductCategory $productCategory): Response
    {
        return Inertia::render('Master/ProductCategories/Form', [
            'productCategory' => $productCategory,
        ]);
    }

    public function update(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $productCategory->update($data);

        return Redirect::route('master.product-categories.index')->with('success', 'Kategori produk berhasil diperbarui.');
    }

    /**
     * `products.product_category_id` di-set `nullOnDelete()` di migrasi —
     * DB TIDAK memblokir hapus, cuma diam-diam mengosongkan kategori pada
     * produk yang masih memakainya. Itu berbahaya (produk jadi tanpa
     * kategori valid tanpa peringatan sama sekali), jadi diblokir
     * eksplisit di sini SEBELUM delete — beda dari `deleteOrFail()` di
     * base Controller yang cuma menangkap FK RESTRICT (QueryException),
     * yang tidak pernah terjadi untuk relasi SET NULL semacam ini.
     */
    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        $count = $productCategory->products()->count();
        if ($count > 0) {
            return Redirect::route('master.product-categories.index')
                ->with('error', "Kategori \"{$productCategory->name}\" masih dipakai oleh {$count} produk, tidak bisa dihapus.");
        }

        $productCategory->delete();

        return Redirect::route('master.product-categories.index')->with('success', 'Kategori produk berhasil dihapus.');
    }
}
