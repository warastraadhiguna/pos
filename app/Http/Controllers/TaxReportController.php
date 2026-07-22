<?php

namespace App\Http\Controllers;

use App\Services\TaxReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxReportController extends Controller
{
    public function __construct(private readonly TaxReportService $reports) {}

    public function ppn(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());

        return Inertia::render('Reports/TaxReport', [
            'start' => $start,
            'end' => $end,
            'report' => $this->reports->ppnReport($start, $end),
        ]);
    }
}
