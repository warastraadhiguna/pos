<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DraftLockedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DraftResource;
use App\Models\Draft;
use App\Services\DraftSyncService;
use App\Support\SyncWatermark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Draft sync lintas-device (langkah 3/3) -- lihat DraftSyncService untuk
 * seluruh rancangan (merge per-item, soft-lock, finalisasi). Controller
 * ini SENGAJA tipis (principle #2): validasi + delegasi, tidak ada
 * keputusan merge/lock apa pun di sini.
 */
class DraftController extends Controller
{
    public function __construct(private readonly DraftSyncService $drafts) {}

    /**
     * Pull -- lihat docblock DraftSyncService::pull() untuk full-vs-
     * incremental. Mobile client HARUS memeriksa `meta.draft_enabled`
     * (piggyback di Api\ProductController::index(), pola sama toggle
     * lain) SEBELUM memanggil endpoint ini sama sekali -- draft murni
     * fitur opsional, pola identik member/table/note.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['updated_since' => ['nullable', 'date']]);
        $syncedAt = SyncWatermark::now();

        $drafts = $this->drafts->pull($validated['updated_since'] ?? null);

        return DraftResource::collection($drafts)
            ->additional(['meta' => ['synced_at' => $syncedAt->toIso8601String()]]);
    }

    /**
     * Push (upsert) -- lihat docblock DraftSyncService::push()/mergeLine()
     * untuk resolusi konflik per-baris. BEDA dari SaleController@store:
     * ini idempotent lewat upsert (bukan create-atau-replay), karena
     * draft mutable berkali-kali sepanjang hidupnya.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'local_uuid' => ['required', 'uuid'],
            'member_id' => ['nullable', 'exists:members,id'],
            'member_name' => ['nullable', 'string', 'max:255'],
            'table_id' => ['nullable', 'exists:tables,id'],
            'table_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['array'],
            'lines.*.local_uuid' => ['required', 'uuid'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.product_name_snapshot' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['required', 'numeric', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
            'lines.*.is_printed' => ['boolean'],
            'lines.*.is_deleted' => ['boolean'],
            // Jam SAAT KONTEN baris ini terakhir berubah di HP -- lihat
            // docblock kolom `content_updated_at` di migrasi `draft_lines`.
            // WAJIB, bukan opsional -- tanpa ini merge per-item tidak
            // mungkin dilakukan sama sekali.
            'lines.*.content_updated_at' => ['required', 'date'],
            'lines.*.variations' => ['array'],
            'lines.*.variations.*.variation_id' => ['required', 'exists:product_variations,id'],
            'lines.*.variations.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.variations.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $draft = $this->drafts->push($validated, $request->user());

        return (new DraftResource($draft))->response()->setStatusCode(200);
    }

    /**
     * Klaim soft-lock. 409 (bukan 422) kalau sedang dipegang device lain
     * & belum kedaluwarsa -- state yang sah & menyelesaikan diri sendiri,
     * sama konvensi CashierMismatchException. Body respons 409 menyertakan
     * `held_by_name`/`held_at` supaya UI mobile bisa menampilkan dialog
     * "Sedang diedit oleh X" tanpa panggilan tambahan.
     */
    public function hold(Request $request, Draft $draft): JsonResponse
    {
        $validated = $request->validate(['force' => ['boolean']]);

        try {
            $draft = $this->drafts->hold(
                $draft,
                $request->user(),
                $request->user()->currentAccessToken()?->name,
                $validated['force'] ?? false,
            );
        } catch (DraftLockedException $e) {
            // `message` di level TERATAS (bukan cuma tersirat lewat
            // `data.held_by_name`) -- ApiClient mobile (lihat
            // `_interpret()`) HANYA membaca `message` top-level saat
            // membentuk ApiException, `data` dari JsonResource wrapper
            // tidak pernah diperiksa. Tanpa ini, alasan penolakan
            // (siapa pemegangnya) tidak akan pernah sampai ke UI kasir.
            return (new DraftResource($e->draft->load('heldByUser')))
                ->additional(['message' => $e->getMessage()])
                ->response()
                ->setStatusCode(409);
        }

        return (new DraftResource($draft->load('heldByUser')))->response();
    }

    public function release(Request $request, Draft $draft): JsonResponse
    {
        $this->drafts->release($draft, $request->user());

        return response()->json(['message' => 'Released.']);
    }

    /**
     * Kasir menghapus draft ini di HP-nya -- lihat docblock
     * `DraftSyncService::cancel()` untuk kenapa ini `status=cancelled`
     * (bukan DELETE fisik dari tabel).
     */
    public function destroy(Draft $draft): JsonResponse
    {
        $this->drafts->cancel($draft);

        return response()->json(['message' => 'Cancelled.']);
    }
}
