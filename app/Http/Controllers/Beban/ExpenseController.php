<?php

namespace App\Http\Controllers\Beban;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\ExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $dateFrom = $filters['date_from'] ?? now()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $search = $filters['search'] ?? '';

        $expenses = Expense::with('expenseAccount')
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('payee', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Beban/Index', [
            'expenses' => $expenses,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Beban/Create', [
            'outlets' => Outlet::orderBy('name')->get(),
            'expenseAccounts' => $this->expenses->selectableExpenseAccounts(),
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'expense_account_id' => ['required', 'exists:accounts,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'in:cash,credit'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'description' => ['required', 'string', 'max:500'],
            'payee' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['created_by_user_id'] = $request->user()->id;

        try {
            $this->expenses->recordExpense($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal mencatat beban: '.$e->getMessage());
        }

        return Redirect::route('beban.index')->with('success', 'Beban berhasil dicatat.');
    }
}
