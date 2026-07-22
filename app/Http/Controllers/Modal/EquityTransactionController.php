<?php

namespace App\Http\Controllers\Modal;

use App\Http\Controllers\Controller;
use App\Models\EquityTransaction;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\EquityTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EquityTransactionController extends Controller
{
    public function __construct(
        private readonly EquityTransactionService $equity,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'type' => ['nullable', 'in:modal,prive'],
        ]);

        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $type = $filters['type'] ?? '';

        $transactions = EquityTransaction::whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Modal/Index', [
            'transactions' => $transactions,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'type' => $type,
            ],
        ]);
    }

    public function createDeposit(): Response
    {
        return Inertia::render('Modal/CreateDeposit', [
            'outlets' => Outlet::orderBy('name')->get(),
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function storeDeposit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['created_by_user_id'] = $request->user()->id;

        try {
            $this->equity->recordModalDeposit($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal mencatat setoran modal: '.$e->getMessage());
        }

        return Redirect::route('modal.index')->with('success', 'Setoran modal berhasil dicatat.');
    }

    public function createWithdrawal(): Response
    {
        return Inertia::render('Modal/CreateWithdrawal', [
            'outlets' => Outlet::orderBy('name')->get(),
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function storeWithdrawal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['created_by_user_id'] = $request->user()->id;

        try {
            $this->equity->recordPriveWithdrawal($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal mencatat pengambilan pribadi: '.$e->getMessage());
        }

        return Redirect::route('modal.index')->with('success', 'Pengambilan pribadi (Prive) berhasil dicatat.');
    }
}
