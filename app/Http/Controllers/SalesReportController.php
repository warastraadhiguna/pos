<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\FinancialReportService;
use App\Services\SalesReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    public function __construct(
        private readonly SalesReportService $reports,
        private readonly FinancialReportService $financialReports,
    ) {}

    public function index(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());
        // Multi-Cabang Lapisan 4 -- null = GABUNGAN semua cabang, perilaku
        // identik sebelum Lapisan 4 ada (default, tanpa filter dipilih).
        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;

        return Inertia::render('Reports/SalesReport', [
            'start' => $start,
            'end' => $end,
            'outletId' => $outletId,
            'report' => $this->reports->salesReport($start, $end, $outletId),
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'multiBranchEnabled' => CompanySetting::current()->multi_branch_enabled,
        ]);
    }

    /**
     * Multi-Cabang Lapisan 4 -- "Perbandingan Cabang", semua cabang aktif
     * bersanding (omzet, laba kotor, laba bersih) untuk satu rentang
     * tanggal. Halaman TERPISAH (bukan filter di laporan lain) karena
     * bentuknya memang berbeda: sate baris per cabang, bukan satu angka
     * gabungan yang bisa difilter.
     */
    public function compare(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());

        return Inertia::render('Reports/BranchComparison', [
            'start' => $start,
            'end' => $end,
            'salesByOutlet' => $this->reports->salesByOutlet($start, $end),
            'incomeByOutlet' => $this->financialReports->incomeStatementByOutlet($start, $end),
            'stockValueByOutlet' => $this->stockValueByOutlet(),
        ]);
    }

    /**
     * Nilai persediaan SAAT INI (bukan per rentang tanggal, stok adalah
     * snapshot) per cabang -- SUM(running_qty * running_average_cost) atas
     * baris stock_movements TERAKHIR tiap item, per warehouse_id ->
     * outlet_id (satu gudang per cabang, Lapisan 1). Query tunggal
     * (subquery id movement terakhir per item+warehouse, join balik) --
     * pola sama InventoryReportController, tidak query per-item di loop.
     *
     * @return array<int, array{outlet_id: int, outlet_name: string, value: string}>
     */
    private function stockValueByOutlet(): array
    {
        $latestMovementIds = StockMovement::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('item_id', 'warehouse_id');

        $valueByWarehouse = StockMovement::query()
            ->joinSub($latestMovementIds, 'latest', 'stock_movements.id', '=', 'latest.id')
            ->selectRaw('warehouse_id, SUM(running_qty * running_average_cost) as value')
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        return Warehouse::with('outlet')->get()
            ->groupBy(fn (Warehouse $w) => $w->outlet_id)
            ->map(function ($warehouses) use ($valueByWarehouse) {
                $outlet = $warehouses->first()->outlet;
                $value = $warehouses->reduce(
                    fn ($carry, Warehouse $w) => bcadd($carry, (string) ($valueByWarehouse->get($w->id)?->value ?? '0'), 4),
                    '0',
                );

                return ['outlet_id' => $outlet->id, 'outlet_name' => $outlet->name, 'value' => $value];
            })
            ->values()
            ->all();
    }
}
