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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            // Opsional -- mobile TIDAK mengirimkan field ini hari ini (client
            // belum diperbarui untuk mengirim productNameSnapshot lokalnya),
            // jadi baris ini akan jatuh ke lookup nama produk saat ini di
            // SaleService::createSaleLine(). Diterima sekarang supaya begitu
            // mobile diperbarui untuk mengirim snapshotnya sendiri, server
            // sudah siap menyimpannya apa adanya tanpa perubahan lebih lanjut.
            'lines.*.product_name' => ['nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
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
        $sale = Sale::where('local_uuid', $localUuid)->with('lines')->first();

        if (! $sale) {
            return response()->json(['message' => 'Sale not found.'], 404);
        }

        return (new SaleResource($sale))->response();
    }
}
