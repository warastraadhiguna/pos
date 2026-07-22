<?php

namespace App\Http\Controllers\Aset;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\FixedAssetPayableReportService;
use App\Services\FixedAssetPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FixedAssetPaymentController extends Controller
{
    public function __construct(
        private readonly FixedAssetPaymentService $payments,
        private readonly FixedAssetPayableReportService $payableReport,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Aset/Payments/Index', [
            'outlets' => Outlet::orderBy('name')->get(),
            'unpaidAssets' => $this->payableReport->unpaidAssets(),
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'fixed_asset_id' => ['required', 'exists:fixed_assets,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['created_by_user_id'] = $request->user()->id;

        try {
            $this->payments->recordPayment($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal mencatat pembayaran: '.$e->getMessage());
        }

        return Redirect::route('aset.payments.index')->with('success', 'Pembayaran hutang aset berhasil dicatat.');
    }
}
