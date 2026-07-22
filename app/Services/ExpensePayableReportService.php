<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\JournalLine;
use DateTimeInterface;

/**
 * Outstanding hutang beban per catatan `expenses` kredit. Sama disiplinnya
 * dengan SupplierPayableReportService: total hutang (`expense_total`)
 * dihitung LIVE dari journal_lines akun 2-2000 (bukan dari kolom cache
 * apa pun), supaya selalu rekonsiliasi dengan saldo Hutang Beban di
 * Neraca. "Sudah dibayar" dihitung dari expense_payments (bukan
 * journal_lines) -- sama seperti "allocated" dihitung dari
 * supplier_payment_allocations, bukan dari jurnal, di
 * SupplierPayableReportService.
 *
 * Tidak ada tabel alokasi di sini (beda dari supplier): satu ExpensePayment
 * selalu menunjuk SATU expense_id, jadi "paid" tinggal SUM(amount) per
 * expense_id secara langsung.
 */
class ExpensePayableReportService
{
    private const SCALE = 4;

    private const ACCOUNT_HUTANG_BEBAN = '2-2000';

    /**
     * Semua catatan beban kredit yang belum lunas, TERTUA DULU. Beban
     * tunai tidak pernah muncul di sini -- tidak pernah ada hutang untuk
     * beban tunai sama sekali.
     *
     * @return array<int, array{
     *     expense_id: int, date: string, description: string, payee: ?string,
     *     expense_total: string, paid: string, remaining: string, status: string,
     * }>
     */
    public function unpaidExpenses(DateTimeInterface|string|null $asOfDate = null): array
    {
        $hutangAccountId = Account::where('code', self::ACCOUNT_HUTANG_BEBAN)->firstOrFail()->id;

        $expenses = Expense::query()
            ->where('payment_method', 'credit')
            ->when($asOfDate, fn ($query) => $query->where('date', '<=', $asOfDate))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return $expenses
            ->map(fn (Expense $expense) => $this->expenseStatus($expense, $hutangAccountId))
            ->filter(fn (array $row) => bccomp($row['remaining'], '0', self::SCALE) > 0)
            ->values()
            ->all();
    }

    /**
     * Total seluruh hutang beban belum lunas -- ini yang harus persis sama
     * dengan saldo akun 2-2000 di Neraca pada tanggal yang sama.
     */
    public function totalOutstanding(DateTimeInterface|string|null $asOfDate = null): string
    {
        return array_reduce(
            $this->unpaidExpenses($asOfDate),
            fn ($carry, array $row) => bcadd($carry, $row['remaining'], self::SCALE),
            '0',
        );
    }

    /**
     * Status satu catatan beban. $hutangAccountId boleh dilewatkan oleh
     * pemanggil beruntun (unpaidExpenses) supaya tidak query akun berulang.
     *
     * @return array{
     *     expense_id: int, date: string, description: string, payee: ?string,
     *     expense_total: string, paid: string, remaining: string, status: string,
     * }
     */
    public function expenseStatus(Expense $expense, ?int $hutangAccountId = null): array
    {
        if ($expense->payment_method !== 'credit') {
            return [
                'expense_id' => $expense->id,
                'date' => (string) $expense->date,
                'description' => $expense->description,
                'payee' => $expense->payee,
                'expense_total' => '0.0000',
                'paid' => '0.0000',
                'remaining' => '0.0000',
                'status' => 'tunai',
            ];
        }

        $hutangAccountId ??= Account::where('code', self::ACCOUNT_HUTANG_BEBAN)->firstOrFail()->id;

        // bcadd(...,'0',SCALE) normalizes SUM()'s output the same way
        // SupplierPayableReportService::notaStatus() does: MySQL returns a
        // proper decimal string when rows match, but Laravel falls back to
        // plain int 0 when none do.
        $expenseTotal = bcadd((string) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.source_type', Expense::class)
            ->where('journals.source_id', $expense->id)
            ->where('journal_lines.account_id', $hutangAccountId)
            ->sum('journal_lines.credit'), '0', self::SCALE);

        $paid = bcadd((string) ExpensePayment::where('expense_id', $expense->id)->sum('amount'), '0', self::SCALE);

        $remaining = bcsub($expenseTotal, $paid, self::SCALE);

        $status = match (true) {
            bccomp($remaining, '0', self::SCALE) <= 0 => 'lunas',
            bccomp($paid, '0', self::SCALE) > 0 => 'sebagian',
            default => 'belum',
        };

        return [
            'expense_id' => $expense->id,
            'date' => (string) $expense->date,
            'description' => $expense->description,
            'payee' => $expense->payee,
            'expense_total' => $expenseTotal,
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => $status,
        ];
    }
}
