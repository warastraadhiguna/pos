<?php

namespace App\Http\Controllers\Coa;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\ChartOfAccountsService;
use App\Services\FinancialReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ChartOfAccountsController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountsService $coa,
        private readonly FinancialReportService $reports,
    ) {}

    /**
     * Daftar SEMUA akun (lintas tipe, termasuk header seperti "Kas &
     * Bank") + saldo per tanggal -- daftar UTAMA CoA. Halaman Kelola Akun
     * Beban/Kas & Bank tetap ada berdampingan untuk alur cepat masing-masing.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        $asOf = $validated['as_of'] ?? now()->toDateString();

        $accounts = collect($this->reports->allAccountBalances($asOf))
            ->map(fn (array $account) => [
                ...$account,
                'is_protected' => $this->coa->isProtected($account['code']),
            ])
            ->values();

        return Inertia::render('Coa/Index', [
            'asOf' => $asOf,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'parent_id' => ['nullable', 'exists:accounts,id'],
        ]);

        try {
            $this->coa->createAccount($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal menambah akun: '.$e->getMessage());
        }

        return Redirect::route('coa.index')->with('success', 'Akun berhasil ditambahkan.');
    }

    public function toggleActive(Account $account): RedirectResponse
    {
        try {
            $this->coa->setActive($account, ! $account->is_active);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal mengubah status akun: '.$e->getMessage());
        }

        return Redirect::route('coa.index')->with('success', 'Status akun diperbarui.');
    }
}
