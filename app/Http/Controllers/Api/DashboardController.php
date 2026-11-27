<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\SalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    private const TOP_PRODUCTS_LIMIT = 5;

    public function __construct(private readonly SalesReportService $reports) {}

    /**
     * Ringkasan "hari ini" untuk layar Dashboard mobile — sengaja
     * membungkus SalesReportService::salesReport() untuk satu hari (bukan
     * query baru): omzet & jumlah transaksi lalu dijamin identik dengan
     * angka yang akan muncul di Laporan Penjualan web untuk rentang
     * tanggal yang sama, dan produk terlaris tinggal mengambil beberapa
     * baris teratas dari `by_product` (sudah terurut turun berdasarkan
     * omzet, lihat SalesReportService::byProduct()).
     *
     * `Carbon::today()` memakai app.timezone (Asia/Jakarta) — "hari ini"
     * di sini selalu WIB, konsisten dengan `sales.date` yang juga
     * disimpan dalam kalender WIB (lihat SaleService).
     *
     * Terpisah dari DashboardController web (yang juga menampilkan PO
     * terbuka, jumlah produk aktif, dsb. — tidak relevan buat kasir di
     * HP) supaya payload mobile tetap ringkas dan tidak ikut berubah
     * kalau dashboard web menambah widget baru.
     */
    public function today(): JsonResponse
    {
        $today = Carbon::today();
        $report = $this->reports->salesReport($today, $today);

        // Rincian tambahan untuk "Cetak Ringkasan Harian" di aplikasi kasir.
        // Hanya penjualan 'completed' yang dihitung (sama seperti
        // SalesReportService); yang batal dilaporkan terpisah.
        $completedToday = Sale::query()->where('status', 'completed')->whereDate('date', $today);
        $byPaymentMethod = (clone $completedToday)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(grand_total) as total')
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => (string) ($row->payment_method ?? 'cash'),
                'count' => (int) $row->count,
                'total' => (string) $row->total,
            ])
            ->values()
            ->all();
        $voidToday = Sale::query()->where('status', 'void')->whereDate('date', $today);

        return response()->json([
            'date' => $today->toDateString(),
            'omzet_today' => $report['totals']['gross'],
            'transaction_count_today' => $report['totals']['transaction_count'],
            'top_products' => array_slice($report['by_product'], 0, self::TOP_PRODUCTS_LIMIT),
            'tax_today' => $report['totals']['tax'],
            'discount_today' => (string) ((clone $completedToday)->sum('discount_amount') ?: '0'),
            'by_payment_method' => $byPaymentMethod,
            'void_count_today' => (clone $voidToday)->count(),
            'void_total_today' => (string) ((clone $voidToday)->sum('grand_total') ?: '0'),
            'products_today' => $report['by_product'],
        ]);
    }
}
