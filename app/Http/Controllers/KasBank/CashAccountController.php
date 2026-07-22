<?php

namespace App\Http\Controllers\KasBank;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\CashAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CashAccountController extends Controller
{
    public function __construct(private readonly CashAccountService $cashAccounts) {}

    public function index(): Response
    {
        $group = Account::where('code', '1-1')->firstOrFail();

        return Inertia::render('KasBank/Accounts/Index', [
            'accounts' => Account::where('parent_id', $group->id)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->cashAccounts->createBankAccount($validated['code'], $validated['name']);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal menambah akun bank: '.$e->getMessage());
        }

        return Redirect::route('kas-bank.accounts.index')->with('success', 'Akun bank berhasil ditambahkan.');
    }

    public function toggleActive(Account $account): RedirectResponse
    {
        try {
            $this->cashAccounts->setCashAccountActive($account, ! $account->is_active);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal mengubah status akun: '.$e->getMessage());
        }

        return Redirect::route('kas-bank.accounts.index')->with('success', 'Status akun diperbarui.');
    }
}
