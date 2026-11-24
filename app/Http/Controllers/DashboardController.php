<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();

        // Hanya penjualan 'completed' -- transaksi yang dibatalkan (void)
        // tidak boleh ikut dihitung, sama seperti SalesReportService.
        $salesToday = Sale::where('status', 'completed')->whereDate('date', $today);
        $salesThisMonth = Sale::where('status', 'completed')->whereBetween('date', [$startOfMonth, $today]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'sales_today_count' => (clone $salesToday)->count(),
                'sales_today_total' => (clone $salesToday)->sum('grand_total'),
                'sales_month_total' => (clone $salesThisMonth)->sum('grand_total'),
                'active_products_count' => Product::where('is_active', true)->count(),
                'open_purchase_orders_count' => PurchaseOrder::whereIn('status', ['open', 'partial'])->count(),
            ],
            'recentSales' => Sale::latest('id')->take(5)->get(['id', 'date', 'occurred_at', 'grand_total', 'payment_method', 'status']),
            'negativeStockItems' => $this->negativeStockItems(),
        ]);
    }

    /**
     * Item aktif yang stoknya minus = terjual sebelum pembeliannya diinput.
     * Pengingat supaya pembelian segera diinput: selama belum, HPP penjualan
     * memakai harga cadangan dan baru dikoreksi saat pembelian masuk (lihat
     * InventoryService::recordOutbound()/recordInbound()).
     */
    private function negativeStockItems(): array
    {
        $latestIds = StockMovement::query()->selectRaw('MAX(id)')->groupBy('item_id', 'warehouse_id');

        return StockMovement::query()
            ->whereIn('stock_movements.id', $latestIds)
            ->where('stock_movements.running_qty', '<', 0)
            ->join('items', 'items.id', '=', 'stock_movements.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->leftJoin('uoms', 'uoms.id', '=', 'items.base_uom_id')
            ->where('items.is_active', true)
            ->orderBy('stock_movements.running_qty')
            ->get(['items.id', 'items.sku', 'items.name', 'uoms.code as uom', 'warehouses.name as warehouse', 'stock_movements.running_qty as qty'])
            ->all();
    }
}
