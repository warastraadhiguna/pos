<?php

namespace App\Services;

use App\Models\Account;
use InvalidArgumentException;

/**
 * Central authority for "which account did this cash movement actually
 * hit" -- Kas, or one of possibly several Bank accounts. Every
 * cash-account-aware transaction (Sale, GoodsReceipt, SupplierPayment,
 * Expense, ExpensePayment, CashTransfer, and future Modal/Prive/Aset
 * features) resolves/validates through here instead of repeating its own
 * "Kas" constant, so adding a new bank account later is a single new
 * Account row -- zero code changes anywhere else that already goes
 * through this service.
 *
 * "Which accounts count as cash/bank" is answered the way a real chart of
 * accounts would: they're the children of the "Kas & Bank" group header
 * account (code "1-1", seeded by the 2026_07_22_100000 migration) --
 * not a bespoke software-only flag. The header itself is never a valid
 * selection; nothing is ever posted to it.
 */
class CashAccountService
{
    private const GROUP_HEADER_CODE = '1-1';

    /** Kas -- the system-wide default when a caller doesn't specify. */
    public const DEFAULT_CODE = '1-1000';

    /**
     * Every active account under the Kas & Bank group -- what a
     * Kas/Bank picker should list.
     *
     * @return array<int, Account>
     */
    public function selectableCashAccounts(): array
    {
        return Account::where('parent_id', $this->groupHeaderId())
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->all();
    }

    /**
     * @throws InvalidArgumentException if $code isn't an active child of the Kas & Bank group.
     */
    public function assertValidCashAccount(string $code): void
    {
        $account = Account::where('code', $code)->first();

        if (! $account || $account->parent_id !== $this->groupHeaderId() || ! $account->is_active) {
            throw new InvalidArgumentException("Akun [{$code}] bukan akun Kas/Bank yang aktif.");
        }
    }

    /**
     * Tambah akun Bank baru -- UI CoA minimal (mirror
     * ExpenseService::createExpenseAccount()): hanya bisa MENAMBAH, tidak
     * pernah edit/hapus. Kode wajib format "1-11xx" (rentang khusus akun
     * Bank tambahan, tidak bentrok dengan Kas 1-1000 atau akun lain
     * seperti Persediaan 1-1200/PPN Masukan 1-1300) dan otomatis jadi
     * child dari grup "Kas & Bank".
     *
     * @throws InvalidArgumentException
     */
    public function createBankAccount(string $code, string $name): Account
    {
        if (! preg_match('/^1-11\d{2}$/', $code)) {
            throw new InvalidArgumentException('Kode akun bank baru harus berformat "1-11xx" (mis. 1-1100, 1-1101, dst).');
        }

        return Account::create([
            'code' => $code,
            'name' => $name,
            'type' => 'asset',
            'normal_balance' => 'debit',
            'parent_id' => $this->groupHeaderId(),
            'is_active' => true,
        ]);
    }

    /**
     * Nonaktifkan/aktifkan kembali akun Kas/Bank -- pengganti "hapus" yang
     * aman terhadap histori jurnal.
     *
     * @throws InvalidArgumentException
     */
    public function setCashAccountActive(Account $account, bool $active): Account
    {
        if ($account->parent_id !== $this->groupHeaderId()) {
            throw new InvalidArgumentException("Akun [{$account->code}] bukan akun Kas/Bank.");
        }

        $account->update(['is_active' => $active]);

        return $account->fresh();
    }

    private function groupHeaderId(): int
    {
        return Account::where('code', self::GROUP_HEADER_CODE)->firstOrFail()->id;
    }
}
