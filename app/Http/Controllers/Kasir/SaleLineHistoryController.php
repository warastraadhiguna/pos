<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riwayat Penjualan per Produk -- sisi lain dari SaleHistoryController:
 * satu baris per sale_line (bukan per nota), supaya bisa menjawab "kapan
 * saja & berapa banyak produk X terjual". Filter sengaja disamakan dengan
 * Riwayat Penjualan (tanggal, kasir, metode bayar, cari No. Transaksi /
 * nama produk) supaya pengguna tidak perlu belajar dua cara, ditambah
 * filter kategori produk.
 */
class SaleLineHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'cashier_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'string', 'in:cash,qris'],
            'product_category_id' => ['nullable', 'integer'],
        ]);

        // Default 7 hari terakhir -- sama dengan Riwayat Penjualan.
        $dateFrom = $filters['date_from'] ?? now()->subDays(6)->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $search = $filters['search'] ?? '';
        $cashierId = $filters['cashier_id'] ?? null;
        $paymentMethod = $filters['payment_method'] ?? null;
        $categoryId = $filters['product_category_id'] ?? null;

        $lines = SaleLine::query()
            ->whereHas('sale', function ($q) use ($dateFrom, $dateTo, $cashierId, $paymentMethod) {
                $q->whereDate('date', '>=', $dateFrom)
                    ->whereDate('date', '<=', $dateTo)
                    ->when($cashierId, fn ($sq) => $sq->where('created_by_user_id', $cashierId))
                    ->when($paymentMethod, fn ($sq) => $sq->where('payment_method', $paymentMethod));
            })
            ->when($categoryId, fn ($q) => $q->whereHas('product', fn ($pq) => $pq->where('product_category_id', $categoryId)))
            ->when($search !== '', function ($q) use ($search) {
                $saleId = ltrim(trim($search), '#');

                // Nama dicocokkan ke snapshot `product_name` (nama SAAT
                // transaksi) DAN nama produk terkini -- produk yang sudah
                // di-rename tetap ketemu lewat nama lama maupun barunya.
                $q->where(function ($sq) use ($search, $saleId) {
                    if ($saleId !== '' && is_numeric($saleId)) {
                        $sq->orWhere('sale_id', 'like', "%{$saleId}%");
                    }
                    $sq->orWhere('product_name', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
                });
            })
            ->with([
                'sale:id,date,occurred_at,payment_method,status,created_by_user_id',
                'sale.createdByUser:id,name',
                'product:id,name,product_category_id',
                'product.productCategory:id,name',
                'variations',
            ])
            ->orderByDesc('sale_id')
            ->orderBy('id')
            ->get();

        $rows = $lines->map(fn (SaleLine $line) => [
            'id' => $line->id,
            'sale_id' => $line->sale_id,
            // toDateString(): Carbon ter-serialisasi ke ISO UTC, yang
            // untuk tanggal tengah malam WIB jatuh ke hari SEBELUMNYA.
            'date' => $line->sale->date?->toDateString(),
            'occurred_at' => $line->sale->occurred_at,
            'cashier' => $line->sale->createdByUser?->name,
            'payment_method' => $line->sale->payment_method,
            'status' => $line->sale->status,
            'product_id' => $line->product_id,
            'product_name' => $this->displayName($line),
            'variations' => $line->variations->pluck('name_snapshot')->filter()->values(),
            'category' => $line->product?->productCategory?->name,
            'qty' => $line->qty,
            'unit_price' => $line->unit_price,
            'line_total' => $line->line_total,
        ]);

        // Total & rekap HANYA dari nota yang tidak dibatalkan -- baris
        // nota batal tetap tampil di tabel (dengan status "Batal") supaya
        // jejaknya terlihat, tapi tidak boleh menggelembungkan angka.
        // bcmath, bukan Collection::sum() -- lihat SaleHistoryController.
        $counted = $rows->where('status', '!=', 'void');

        $recap = $counted
            ->groupBy(fn (array $row) => $row['product_id'] ?? 'name:'.$row['product_name'])
            ->map(fn ($group, $key) => [
                'key' => (string) $key,
                'product_name' => $group->first()['product_name'],
                'category' => $group->first()['category'],
                'qty' => $group->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['qty'], 4), '0'),
                'total' => $group->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['line_total'], 4), '0'),
                'transaction_count' => $group->pluck('sale_id')->unique()->count(),
            ])
            ->sortByDesc(fn (array $row) => (float) $row['total'])
            ->values();

        return Inertia::render('Penjualan/PerProduk', [
            'rows' => $rows,
            'recap' => $recap,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
                'cashier_id' => $cashierId,
                'payment_method' => $paymentMethod,
                'product_category_id' => $categoryId,
            ],
            'cashiers' => User::whereIn('id', Sale::query()->whereNotNull('created_by_user_id')->distinct()->pluck('created_by_user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'summary' => [
                'line_count' => $rows->count(),
                'qty' => $counted->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['qty'], 4), '0'),
                'total' => $counted->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['line_total'], 4), '0'),
                'void_line_count' => $rows->count() - $counted->count(),
            ],
        ]);
    }

    /**
     * Snapshot nama saat transaksi lebih dulu (baris lama sebelum kolom
     * snapshot ada bernilai null -> jatuh ke nama produk terkini).
     */
    private function displayName(SaleLine $line): string
    {
        return $line->product_name ?: ($line->product?->name ?? '(produk terhapus)');
    }
}
