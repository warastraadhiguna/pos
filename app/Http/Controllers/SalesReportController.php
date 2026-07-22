<?php

namespace App\Http\Controllers;

use App\Services\SalesReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    public function __construct(private readonly SalesReportService $reports) {}

    public function index(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());

        return Inertia::render('Reports/SalesReport', [
            'start' => $start,
            'end' => $end,
            'report' => $this->reports->salesReport($start, $end),
        ]);
    }
}
