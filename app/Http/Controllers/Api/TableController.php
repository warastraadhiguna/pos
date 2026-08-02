<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TableResource;
use App\Models\DiningTable;
use App\Support\SyncWatermark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TableController extends Controller
{
    /**
     * Meja master data for the mobile client to cache offline, so checkout
     * can offer "pick from list" alongside free-typed table names. Same
     * ?updated_since= incremental sync as products/members. Returns every
     * matching table regardless of is_active, so a client that already
     * synced a table which was since deactivated can still show it was
     * deactivated — filtering for the checkout picker (active only) is the
     * client's job, same convention as Member/Product/Item's is_active.
     *
     * The mobile client MUST check meta.table_enabled (piggybacked on
     * Api\ProductController::index()) BEFORE calling this endpoint at all —
     * when the feature is off, table data should never be pulled onto the
     * device in the first place.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['updated_since' => ['nullable', 'date']]);
        $syncedAt = SyncWatermark::now();

        $tables = SyncWatermark::applyIncrementalFilter(
            DiningTable::query(),
            $validated['updated_since'] ?? null,
        )->orderBy('id')->get();

        return TableResource::collection($tables)
            ->additional(['meta' => ['synced_at' => $syncedAt->toIso8601String()]]);
    }
}
