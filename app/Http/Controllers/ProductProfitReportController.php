<?php

namespace App\Http\Controllers;

use App\Services\ProductProfitReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductProfitReportController extends Controller
{
    public function __construct(private readonly ProductProfitReportService $reports) {}

    public function index(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());
        $sort = $request->input('sort', 'gross_profit') === 'margin' ? 'margin' : 'gross_profit';

        return Inertia::render('Reports/ProductProfitReport', [
            'start' => $start,
            'end' => $end,
            'sort' => $sort,
            'report' => $this->reports->productProfitReport($start, $end, $sort),
        ]);
    }
}
