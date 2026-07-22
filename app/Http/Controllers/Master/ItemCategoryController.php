<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ItemCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Master/ItemCategories/Index', [
            'itemCategories' => ItemCategory::orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/ItemCategories/Form', [
            'itemCategory' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        ItemCategory::create($data);

        return Redirect::route('master.item-categories.index')->with('success', 'Kategori item berhasil ditambahkan.');
    }

    public function edit(ItemCategory $itemCategory): Response
    {
        return Inertia::render('Master/ItemCategories/Form', [
            'itemCategory' => $itemCategory,
        ]);
    }

    public function update(Request $request, ItemCategory $itemCategory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $itemCategory->update($data);

        return Redirect::route('master.item-categories.index')->with('success', 'Kategori item berhasil diperbarui.');
    }

    /**
     * Sama seperti ProductCategoryController::destroy() — lihat komentar
     * di sana. `items.item_category_id` juga `nullOnDelete()`, jadi
     * pengecekan pemakaian dilakukan eksplisit di sini, bukan mengandalkan
     * exception dari DB.
     */
    public function destroy(ItemCategory $itemCategory): RedirectResponse
    {
        $count = $itemCategory->items()->count();
        if ($count > 0) {
            return Redirect::route('master.item-categories.index')
                ->with('error', "Kategori \"{$itemCategory->name}\" masih dipakai oleh {$count} item, tidak bisa dihapus.");
        }

        $itemCategory->delete();

        return Redirect::route('master.item-categories.index')->with('success', 'Kategori item berhasil dihapus.');
    }
}
