<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Support\SyncWatermark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductCategoryController extends Controller
{
    /**
     * Product categories, for grouping the sell screen on the mobile
     * client. Supports the same ?updated_since= incremental sync as
     * products/items.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['updated_since' => ['nullable', 'date']]);
        $syncedAt = SyncWatermark::now();

        $categories = SyncWatermark::applyIncrementalFilter(
            ProductCategory::query(),
            $validated['updated_since'] ?? null,
        )->orderBy('id')->get();

        return ProductCategoryResource::collection($categories)
            ->additional(['meta' => ['synced_at' => $syncedAt->toIso8601String()]]);
    }
}
