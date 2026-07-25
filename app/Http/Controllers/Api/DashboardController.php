<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'date' => $today->toDateString(),
            'omzet_today' => $report['totals']['gross'],
            'transaction_count_today' => $report['totals']['transaction_count'],
            'top_products' => array_slice($report['by_product'], 0, self::TOP_PRODUCTS_LIMIT),
        ]);
    }
}
