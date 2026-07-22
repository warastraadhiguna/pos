<?php

namespace App\Http\Controllers\KasBank;

use App\Http\Controllers\Controller;
use App\Models\CashTransfer;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\CashTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CashTransferController extends Controller
{
    public function __construct(
        private readonly CashTransferService $transfers,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $dateFrom = $filters['date_from'] ?? now()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();

        $transfers = CashTransfer::whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->orderByDesc('id')
            ->get();

        return Inertia::render('KasBank/Transfers/Index', [
            'transfers' => $transfers,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('KasBank/Transfers/Create', [
            'outlets' => Outlet::orderBy('name')->get(),
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'date' => ['required', 'date'],
            'from_account_code' => ['required', 'string', 'max:20'],
            'to_account_code' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['created_by_user_id'] = $request->user()->id;

        try {
            $this->transfers->recordTransfer($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal mencatat transfer: '.$e->getMessage());
        }

        return Redirect::route('kas-bank.transfers.index')->with('success', 'Transfer berhasil dicatat.');
    }
}
