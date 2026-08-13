<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialReportController extends Controller
{
    public function __construct(private readonly FinancialReportService $reports) {}

    /**
     * Neraca -- SENGAJA TIDAK menerima/meneruskan filter cabang sama
     * sekali (Multi-Cabang Lapisan 4, keputusan yang disetujui secara
     * eksplisit): sebagian pos (Modal, Aset Tetap, Hutang Usaha) milik
     * bisnis keseluruhan, bukan per-cabang -- memaksa Neraca per-cabang
     * akan menghasilkan angka TIDAK SEIMBANG yang menyesatkan. `cashByOutlet`
     * di bawah adalah RINCIAN jujur (Kas/Bank yang memang outlet_id-nya
     * terisi), BUKAN "Neraca cabang A" -- lihat docblock
     * FinancialReportService::balanceSheet()/cashByOutlet().
     */
    public function balanceSheet(Request $request): Response
    {
        $asOf = $request->input('as_of', now()->toDateString());

        return Inertia::render('Reports/BalanceSheet', [
            'asOf' => $asOf,
            'report' => $this->reports->balanceSheet($asOf),
            'cashByOutlet' => $this->reports->cashByOutlet($asOf),
            'multiBranchEnabled' => CompanySetting::current()->multi_branch_enabled,
        ]);
    }

    public function incomeStatement(Request $request): Response
    {
        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->toDateString());
        // Multi-Cabang Lapisan 4 -- null = GABUNGAN, perilaku identik
        // sebelum Lapisan 4 ada (default, tanpa filter dipilih). BEDA dari
        // Neraca: Laba-Rugi bermakna & SEIMBANG per-cabang (murni
        // pendapatan/beban periode berjalan), lihat docblock
        // FinancialReportService::incomeStatement().
        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;

        return Inertia::render('Reports/IncomeStatement', [
            'start' => $start,
            'end' => $end,
            'outletId' => $outletId,
            'report' => $this->reports->incomeStatement($start, $end, $outletId),
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'multiBranchEnabled' => CompanySetting::current()->multi_branch_enabled,
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
        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;

        $report = $this->reports->incomeStatement($start, $end, $outletId);

        return Inertia::render('Reports/ExpenseReport', [
            'start' => $start,
            'end' => $end,
            'outletId' => $outletId,
            'expenses' => $report['operational_expenses'],
            'totalExpense' => $report['total_operational_expense'],
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'multiBranchEnabled' => CompanySetting::current()->multi_branch_enabled,
        ]);
    }
}
