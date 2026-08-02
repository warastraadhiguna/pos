<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Support\SyncWatermark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MemberController extends Controller
{
    /**
     * Member/pelanggan master data for the mobile client to cache offline,
     * so checkout can offer "pick from list" alongside free-typed names.
     * Same ?updated_since= incremental sync as products/items. Returns
     * every matching member regardless of is_active, so a client that
     * already synced a member who was since deactivated can still show it
     * was deactivated — filtering for the checkout picker (active only) is
     * the client's job, same convention as Product/Item's is_active.
     *
     * The mobile client MUST check meta.member_enabled (piggybacked on
     * Api\ProductController::index()) BEFORE calling this endpoint at all —
     * when the feature is off, member data (name/phone/email — PII) should
     * never be pulled onto the device in the first place.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['updated_since' => ['nullable', 'date']]);
        $syncedAt = SyncWatermark::now();

        $members = SyncWatermark::applyIncrementalFilter(
            Member::query(),
            $validated['updated_since'] ?? null,
        )->orderBy('id')->get();

        return MemberResource::collection($members)
            ->additional(['meta' => ['synced_at' => $syncedAt->toIso8601String()]]);
    }
}
