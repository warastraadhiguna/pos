<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CashierMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    /**
     * Sync a sale created offline on the mobile client.
     *
     * local_uuid is required (not optional, unlike the web kasir) — this
     * endpoint exists specifically for offline-first sync, so the client
     * MUST generate it before the sale ever reaches the server. Retrying
     * this request with the same local_uuid after a dropped connection is
     * always safe and always returns 201 with identical data — see
     * SaleService::createSale() for the two-layer idempotency guarantee.
     * meta.replayed tells the caller whether this was a fresh sale or an
     * idempotent replay, purely for telemetry; the mobile client should
     * treat both as success and is free to ignore it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'local_uuid' => ['required', 'uuid'],
            'date' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'in:cash'],
            // Wajib di endpoint mobile ini (checkout selalu mencatat uang
            // tunai diterima) — beda dari Kasir\SaleController (web) yang
            // tidak mengumpulkan ini sama sekali. SaleService::createSale()
            // yang melakukan validasi rekonsiliasi sungguhan (cash_received
            // >= grand_total, change_amount = cash_received − grand_total)
            // karena grand_total baru diketahui setelah baris-baris diproses.
            'cash_received' => ['required', 'numeric', 'min:0'],
            'change_amount' => ['required', 'numeric', 'min:0'],
            // Klaim opsional, TIDAK pernah dipercaya begitu saja — lihat
            // docblock CashierMismatchException & SaleService::createSale().
            // Cuma diperiksa silang terhadap pemilik token yang benar-benar
            // mengautentikasi request ini; null berarti klien lama yang
            // belum mengirimkannya, jatuh ke perilaku hari ini apa adanya.
            'client_user_id' => ['nullable', 'integer'],
            // Keduanya opsional & independen -- lihat docblock
            // SaleService::createSale(). member_name, kalau dikirim, SELALU
            // menang atas nama Member saat ini (kasus offline: nama saat
            // benar-benar dipilih/diketik, yang mungkin berbeda dari nama
            // Member sekarang kalau member itu sempat di-rename sebelum sale
            // ini akhirnya di-push ke server).
            'member_id' => ['nullable', 'exists:members,id'],
            'member_name' => ['nullable', 'string', 'max:255'],
            // Sama pola member_id/member_name di atas -- lihat docblock
            // SaleService::createSale().
            'table_id' => ['nullable', 'exists:tables,id'],
            'table_name' => ['nullable', 'string', 'max:255'],
            // Catatan per-transaksi & per-item -- keduanya teks bebas apa
            // adanya, lihat docblock SaleService::createSale().
            'note' => ['nullable', 'string', 'max:2000'],
            // Langkah 3 fitur Draft -- diisi HANYA kalau sale ini
            // finalisasi sebuah draft (lihat `checkout_sheet.dart`
            // mobile). Opsional & tervalidasi longgar (`uuid` saja, BUKAN
            // `exists:drafts,local_uuid`) -- draft yang belum pernah
            // sync sama sekali (mis. dibuat sebelum fitur ini ada) tetap
            // harus bisa difinalisasi tanpa gagal cuma karena servernya
            // tidak pernah dengar soal draft itu, lihat docblock
            // DraftSyncService::finalizeByLocalUuid().
            'draft_local_uuid' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
            // Opsional -- mobile TIDAK mengirimkan field ini hari ini (client
            // belum diperbarui untuk mengirim productNameSnapshot lokalnya),
            // jadi baris ini akan jatuh ke lookup nama produk saat ini di
            // SaleService::createSaleLine(). Diterima sekarang supaya begitu
            // mobile diperbarui untuk mengirim snapshotnya sendiri, server
            // sudah siap menyimpannya apa adanya tanpa perubahan lebih lanjut.
            'lines.*.product_name' => ['nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            // Variasi Berbayar (Tahap 1, harga-saja) -- lihat docblock
            // SaleService::createSale(). unit_price di atas SUDAH termasuk
            // additional_price tiap variasi terpilih (dihitung klien);
            // array ini murni rincian snapshot untuk nota, tidak memengaruhi
            // perhitungan harga sama sekali.
            'lines.*.variations' => ['array'],
            'lines.*.variations.*.variation_id' => ['required', 'exists:product_variations,id'],
            'lines.*.variations.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.variations.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Satu outlet untuk sekarang (lihat docs/ROADMAP.md) — begitu
        // multi-outlet dibutuhkan, outlet harus datang dari device/pilihan
        // kasir yang login, bukan first().
        $outlet = Outlet::firstOrFail();
        $warehouse = Warehouse::where('outlet_id', $outlet->id)->firstOrFail();

        try {
            $sale = $this->sales->createSale([
                'outlet_id' => $outlet->id,
                'warehouse_id' => $warehouse->id,
                'created_by_user_id' => $request->user()->id,
                'client_user_id' => $validated['client_user_id'] ?? null,
                'device_label' => $request->user()->currentAccessToken()?->name,
                'date' => $validated['date'],
                'local_uuid' => $validated['local_uuid'],
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'cash_received' => $validated['cash_received'],
                'change_amount' => $validated['change_amount'],
                'member_id' => $validated['member_id'] ?? null,
                'member_name' => $validated['member_name'] ?? null,
                'table_id' => $validated['table_id'] ?? null,
                'table_name' => $validated['table_name'] ?? null,
                'note' => $validated['note'] ?? null,
                'draft_local_uuid' => $validated['draft_local_uuid'] ?? null,
                'lines' => $validated['lines'],
            ]);
        } catch (CashierMismatchException $e) {
            // 409, bukan 422: bukan payload yang cacat, melainkan "kasir yang
            // salah sedang login di perangkat ini sekarang" -- keadaan yang
            // sah dan menyelesaikan diri sendiri begitu kasir yang benar
            // login lagi. Mobile client HARUS menjaga sale ini tetap
            // `pending` (lihat HttpSalePushRepository), tidak pernah `failed`.
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal membuat transaksi: '.$e->getMessage()], 422);
        }

        return (new SaleResource($sale))
            ->additional(['meta' => ['replayed' => $sale->wasReplayed]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Status check by local_uuid, for a client that's unsure whether a
     * previous POST actually landed (e.g. connection dropped before the
     * response arrived) — a plain read, safe to call before deciding
     * whether to retry the POST at all.
     */
    public function show(string $localUuid): JsonResponse
    {
        $sale = Sale::where('local_uuid', $localUuid)->with('lines.variations')->first();

        if (! $sale) {
            return response()->json(['message' => 'Sale not found.'], 404);
        }

        return (new SaleResource($sale))->response();
    }
}
