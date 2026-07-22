<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\SupplierPayableReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierPayableReportController extends Controller
{
    public function __construct(private readonly SupplierPayableReportService $reports) {}

    public function index(Request $request): Response
    {
        $asOf = $request->input('as_of', now()->toDateString());

        return Inertia::render('Reports/SupplierPayable/Index', [
            'asOf' => $asOf,
            'rows' => $this->reports->outstandingBySupplier($asOf),
            'total' => $this->reports->totalOutstanding($asOf),
        ]);
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        $asOf = $request->input('as_of', now()->toDateString());

        return Inertia::render('Reports/SupplierPayable/Show', [
            'supplier' => $supplier,
            'asOf' => $asOf,
            'outstanding' => $this->reports->outstandingForSupplier($supplier->id, $asOf),
            'notas' => $this->reports->notaBreakdownForSupplier($supplier->id, $asOf),
        ]);
    }
}
