<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();

        $salesToday = Sale::whereDate('date', $today);
        $salesThisMonth = Sale::whereBetween('date', [$startOfMonth, $today]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'sales_today_count' => (clone $salesToday)->count(),
                'sales_today_total' => (clone $salesToday)->sum('grand_total'),
                'sales_month_total' => (clone $salesThisMonth)->sum('grand_total'),
                'active_products_count' => Product::where('is_active', true)->count(),
                'open_purchase_orders_count' => PurchaseOrder::whereIn('status', ['open', 'partial'])->count(),
            ],
            'recentSales' => Sale::latest('id')->take(5)->get(['id', 'date', 'grand_total', 'payment_method', 'status']),
        ]);
    }
}
