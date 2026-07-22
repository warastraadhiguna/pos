<?php

namespace App\Http\Controllers;

use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialReportController extends Controller
{
    public function __construct(private readonly FinancialReportService $reports) {}

    public function balanceSheet(Request $request): Response
    {
        $asOf = $request->input('as_of', now()->toDateString());

        return Inertia::render('Reports/BalanceSheet', [
            'asOf' => $asOf,
            'report' => $this->reports->balanceSheet($asOf),
        ]);
    }

    public function incomeStatement(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());

        return Inertia::render('Reports/IncomeStatement', [
            'start' => $start,
            'end' => $end,
            'report' => $this->reports->incomeStatement($start, $end),
        ]);
    }

    /**
     * Ringkasan beban operasional per akun untuk suatu periode -- murni
     * menyajikan ulang `operational_expenses`/`total_operational_expense`
     * yang sudah dihitung oleh incomeStatement() (bucket yang sama dipakai
     * di Laba Rugi), jadi tidak ada rumus kedua yang bisa menyimpang.
     */
    public function expenseReport(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());

        $report = $this->reports->incomeStatement($start, $end);

        return Inertia::render('Reports/ExpenseReport', [
            'start' => $start,
            'end' => $end,
            'expenses' => $report['operational_expenses'],
            'totalExpense' => $report['total_operational_expense'],
        ]);
    }
}
