<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NoteTemplateResource;
use App\Models\NoteTemplate;
use App\Support\SyncWatermark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NoteTemplateController extends Controller
{
    /**
     * Note template master data for the mobile client to cache offline, so
     * both per-sale and per-line note fields can offer "tap to fill" chips
     * alongside free typing. Same ?updated_since= incremental sync as
     * members/tables. Returns every matching template regardless of
     * is_active, so a client that already synced a template since
     * deactivated can still know about it — filtering for the picker
     * (active only) is the client's job, same convention as Member/Table.
     *
     * The mobile client MUST check meta.note_enabled (piggybacked on
     * Api\ProductController::index()) BEFORE calling this endpoint at all —
     * when the feature is off, note fields aren't shown anywhere and
     * templates shouldn't be pulled onto the device either.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['updated_since' => ['nullable', 'date']]);
        $syncedAt = SyncWatermark::now();

        $noteTemplates = SyncWatermark::applyIncrementalFilter(
            NoteTemplate::query(),
            $validated['updated_since'] ?? null,
        )->orderBy('id')->get();

        return NoteTemplateResource::collection($noteTemplates)
            ->additional(['meta' => ['synced_at' => $syncedAt->toIso8601String()]]);
    }
}
