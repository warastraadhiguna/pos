<?php

namespace App\Http\Controllers\Beban;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\ExpensePayableReportService;
use App\Services\ExpensePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ExpensePaymentController extends Controller
{
    public function __construct(
        private readonly ExpensePaymentService $payments,
        private readonly ExpensePayableReportService $payableReport,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Beban/Payments/Index', [
            'outlets' => Outlet::orderBy('name')->get(),
            'unpaidExpenses' => $this->payableReport->unpaidExpenses(),
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'expense_id' => ['required', 'exists:expenses,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->payments->recordPayment($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal mencatat pembayaran: '.$e->getMessage());
        }

        return Redirect::route('beban.payments.index')->with('success', 'Pembayaran hutang beban berhasil dicatat.');
    }
}
