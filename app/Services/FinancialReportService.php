<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalLine;
use DateTimeInterface;
use Illuminate\Support\Collection;

class FinancialReportService
{
    private const SCALE = 4;

    /**
     * Code prefixes that count as COGS for the Laba Kotor subtotal (HPP
     * 5-1000 and Selisih Persediaan 5-2000). Every other expense account
     * (5-3xxx+, the operational-expense range created for the "beban
     * operasional" feature) falls into the operational bucket instead.
     * Grouping by code prefix -- rather than adding a schema column like
     * `report_group` -- was a deliberate choice: no migration needed, and
     * consistent with how this CoA already partitions meaning into code
     * ranges (1-1xxx assets, 2-1xxx liabilities, etc.). The trade-off is
     * that a future expense account would need to stay outside 5-1x/5-2x
     * to land in the operational bucket -- acceptable since those two
     * codes are reserved for system-only postings (see
     * ExpenseService::RESERVED_SYSTEM_EXPENSE_CODES) and are never
     * assigned to new accounts.
     */
    private const COGS_ACCOUNT_CODE_PREFIXES = ['5-1', '5-2'];

    /**
     * Balance sheet as of a given date.
     *
     * This system has no period-closing mechanism (no journal ever debits
     * Revenue/credits an equity account to "close the books"), regardless
     * of how many real equity accounts exist (Modal Pemilik 3-1000, Prive
     * 3-2000). Without something standing in for retained earnings, Assets
     * would almost never equal Liabilities + Equity. So year-to-date net
     * income (all revenue minus all expense, as of $asOfDate) is computed
     * live and shown as a "Laba/Rugi Berjalan" line ADDED ON TOP of the
     * real equity accounts below — a report-only construct, not a real
     * journal — which is what makes the sheet balance without manual
     * closing entries.
     *
     * Prive is a CONTRA-equity account seeded with normal_balance='credit'
     * (opposite of what its debit-heavy transactions would suggest) --
     * this makes accountsForType()'s "normal positive balance" formula
     * naturally compute it as NEGATIVE, so the plain sumBalances() call
     * below already nets Modal + Prive into (Modal − withdrawals) with no
     * special-case subtraction logic here. See
     * EquityTransactionService/the 2026_07_22_150000 migration.
     *
     * @param  DateTimeInterface|string  $asOfDate
     * @return array{
     *     as_of: DateTimeInterface|string,
     *     assets: array, total_assets: string,
     *     liabilities: array, total_liabilities: string,
     *     equity: array, total_equity: string,
     *     total_liabilities_and_equity: string,
     *     is_balanced: bool,
     * }
     */
    public function balanceSheet(DateTimeInterface|string $asOfDate): array
    {
        $balances = $this->accountBalances(upToDate: $asOfDate);

        $assets = $this->accountsForType('asset', $balances);
        $liabilities = $this->accountsForType('liability', $balances);
        $equity = $this->accountsForType('equity', $balances);

        $totalAssets = $this->sumBalances($assets);
        $totalLiabilities = $this->sumBalances($liabilities);
        $totalEquityAccounts = $this->sumBalances($equity);

        $totalRevenue = $this->sumBalances($this->accountsForType('revenue', $balances));
        $totalExpense = $this->sumBalances($this->accountsForType('expense', $balances));
        $currentEarnings = bcsub($totalRevenue, $totalExpense, self::SCALE);

        $equity[] = [
            'id' => null,
            'code' => null,
            'name' => 'Laba/Rugi Berjalan (belum ditutup)',
            'balance' => $currentEarnings,
        ];
        $totalEquity = bcadd($totalEquityAccounts, $currentEarnings, self::SCALE);
        $totalLiabilitiesAndEquity = bcadd($totalLiabilities, $totalEquity, self::SCALE);

        return [
            'as_of' => $asOfDate,
            'assets' => $assets,
            'total_assets' => $totalAssets,
            'liabilities' => $liabilities,
            'total_liabilities' => $totalLiabilities,
            'equity' => $equity,
            'total_equity' => $totalEquity,
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'is_balanced' => bccomp($totalAssets, $totalLiabilitiesAndEquity, self::SCALE) === 0,
        ];
    }

    /**
     * Income statement (laba rugi) for a date range.
     *
     * `cogs_expenses`/`operational_expenses` split `expenses` into the two
     * buckets needed for the Laba Kotor -> Beban Operasional -> Laba
     * Bersih presentation (see COGS_ACCOUNT_CODE_PREFIXES). `expenses`/
     * `total_expense` are kept exactly as before (the flat, undivided
     * list) so existing readers of this array are unaffected -- this is
     * additive, not a breaking change. `gross_profit` and `net_income` are
     * mathematically consistent by construction: gross_profit -
     * total_operational_expense always equals total_revenue -
     * total_expense, since cogs + operational = the same total_expense as
     * before, just partitioned.
     *
     * @param  DateTimeInterface|string  $startDate
     * @param  DateTimeInterface|string  $endDate
     * @return array{
     *     start: DateTimeInterface|string, end: DateTimeInterface|string,
     *     revenues: array, total_revenue: string,
     *     cogs_expenses: array, total_cogs: string, gross_profit: string,
     *     operational_expenses: array, total_operational_expense: string,
     *     expenses: array, total_expense: string,
     *     net_income: string,
     * }
     */
    public function incomeStatement(DateTimeInterface|string $startDate, DateTimeInterface|string $endDate): array
    {
        $balances = $this->accountBalances(fromDate: $startDate, upToDate: $endDate);

        $revenues = $this->accountsForType('revenue', $balances);
        $expenses = $this->accountsForType('expense', $balances);

        $cogsExpenses = array_values(array_filter($expenses, fn (array $account) => $this->isCogsAccountCode($account['code'])));
        $operationalExpenses = array_values(array_filter($expenses, fn (array $account) => ! $this->isCogsAccountCode($account['code'])));

        $totalRevenue = $this->sumBalances($revenues);
        $totalCogs = $this->sumBalances($cogsExpenses);
        $totalOperationalExpense = $this->sumBalances($operationalExpenses);
        $totalExpense = $this->sumBalances($expenses);
        $grossProfit = bcsub($totalRevenue, $totalCogs, self::SCALE);

        return [
            'start' => $startDate,
            'end' => $endDate,
            'revenues' => $revenues,
            'total_revenue' => $totalRevenue,
            'cogs_expenses' => $cogsExpenses,
            'total_cogs' => $totalCogs,
            'gross_profit' => $grossProfit,
            'operational_expenses' => $operationalExpenses,
            'total_operational_expense' => $totalOperationalExpense,
            'expenses' => $expenses,
            'total_expense' => $totalExpense,
            'net_income' => bcsub($grossProfit, $totalOperationalExpense, self::SCALE),
        ];
    }

    private function isCogsAccountCode(string $code): bool
    {
        foreach (self::COGS_ACCOUNT_CODE_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `whereDoesntHave('children')` excludes group/header accounts (e.g.
     * the "Kas & Bank" header, code "1-1", seeded for the Kas/Bank
     * separation feature) -- those are never posted to and exist purely
     * for structural grouping via parent_id, so listing them here would
     * only add a redundant zero-balance row next to their real children
     * (Kas, Bank, ...). No existing account had any children before that
     * feature, so this is a no-op for every account that predates it.
     * Chart of Accounts (allAccountBalances()) deliberately does NOT apply
     * this filter -- it needs to show the header itself for hierarchy.
     *
     * @return array<int, array{id: int, code: string, name: string, balance: string}>
     */
    private function accountsForType(string $type, Collection $balances): array
    {
        return Account::where('type', $type)->whereDoesntHave('children')->orderBy('code')->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'balance' => $this->balanceFor($account, $balances),
            ])
            ->all();
    }

    /**
     * Saldo SEMUA akun lintas tipe (termasuk akun header/group seperti
     * "Kas & Bank" itu sendiri -- beda dari accountsForType() yang
     * menyembunyikannya) -- dipakai oleh halaman Chart of Accounts untuk
     * menampilkan setiap akun + saldonya per tanggal, apa pun tipenya.
     * Sama sekali tidak mengubah balanceSheet()/incomeStatement() di atas.
     *
     * @return array<int, array{
     *     id: int, code: string, name: string, type: string,
     *     normal_balance: string, parent_id: ?int, is_active: bool, balance: string,
     * }>
     */
    public function allAccountBalances(DateTimeInterface|string $asOfDate): array
    {
        $balances = $this->accountBalances(upToDate: $asOfDate);

        return Account::orderBy('type')->orderBy('code')->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'parent_id' => $account->parent_id,
                'is_active' => $account->is_active,
                'balance' => $this->balanceFor($account, $balances),
            ])
            ->all();
    }

    /**
     * "Normal positive" balance: debit-normal accounts grow with debits,
     * credit-normal accounts grow with credits. Contra accounts (Prive,
     * Akumulasi Penyusutan) are seeded with the SAME normal_balance as
     * their category's dominant convention, so this formula naturally
     * computes them as negative -- see the 2026_07_22_150000/170000
     * migrations.
     */
    private function balanceFor(Account $account, Collection $balances): string
    {
        $row = $balances->get($account->id);
        $debit = (string) ($row?->total_debit ?? '0');
        $credit = (string) ($row?->total_credit ?? '0');

        return $account->normal_balance === 'debit'
            ? bcsub($debit, $credit, self::SCALE)
            : bcsub($credit, $debit, self::SCALE);
    }

    /**
     * @param  array<int, array{balance: string}>  $accounts
     */
    private function sumBalances(array $accounts): string
    {
        return array_reduce(
            $accounts,
            fn ($carry, $account) => bcadd($carry, $account['balance'], self::SCALE),
            '0'
        );
    }

    /**
     * Sum debit/credit per account from journal_lines, optionally scoped to
     * a date range on the parent journal.
     */
    private function accountBalances(DateTimeInterface|string|null $fromDate = null, DateTimeInterface|string|null $upToDate = null): Collection
    {
        $query = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->selectRaw('journal_lines.account_id as account_id, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->groupBy('journal_lines.account_id');

        if ($fromDate) {
            $query->where('journals.date', '>=', $fromDate);
        }

        if ($upToDate) {
            $query->where('journals.date', '<=', $upToDate);
        }

        return $query->get()->keyBy('account_id');
    }
}
