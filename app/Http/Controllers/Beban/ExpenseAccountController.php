<?php

namespace App\Http\Controllers\Beban;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\ExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Kelola Akun Beban" -- CoA UI minimal poin 1 rancangan. Sengaja BUKAN
 * CRUD penuh Chart of Accounts (yang masih tidak punya UI sama sekali):
 * cuma bisa menambah akun baru bertipe expense di rentang 5-3xxx, dan
 * menonaktifkan/mengaktifkan kembali -- tidak pernah edit/hapus, supaya
 * akun yang sudah dipakai di jurnal tidak pernah berubah makna.
 */
class ExpenseAccountController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(): Response
    {
        return Inertia::render('Beban/Accounts/Index', [
            'accounts' => Account::where('type', 'expense')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->expenses->createExpenseAccount($validated['code'], $validated['name']);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal menambah akun beban: '.$e->getMessage());
        }

        return Redirect::route('beban.accounts.index')->with('success', 'Akun beban berhasil ditambahkan.');
    }

    public function toggleActive(Account $account): RedirectResponse
    {
        try {
            $this->expenses->setExpenseAccountActive($account, ! $account->is_active);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal mengubah status akun: '.$e->getMessage());
        }

        return Redirect::route('beban.accounts.index')->with('success', 'Status akun beban diperbarui.');
    }
}
