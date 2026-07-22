<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpensePayment;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExpensePaymentService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by the 2026_07_21_170000 migration.
    private const ACCOUNT_HUTANG_BEBAN = '2-2000';

    public function __construct(
        private readonly PostingService $posting,
        private readonly ExpensePayableReportService $payableReport,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Lunasi (boleh parsial) SATU catatan beban kredit -- selalu Dr Hutang
     * Beban / Cr {cash_account_code}, satu jurnal per pembayaran. Mirror
     * jurnal SupplierPaymentService::recordPayment(), tapi TANPA tabel
     * alokasi: satu ExpensePayment selalu menunjuk satu expense_id (lihat
     * dokumen rancangan untuk alasan model 1:1 ini, bukan FIFO lintas
     * banyak catatan).
     *
     * @param  array{outlet_id: int, expense_id: int, date: DateTimeInterface|string, amount: int|float|string, cash_account_code?: ?string, memo?: ?string}  $data
     *
     * @throws InvalidArgumentException if the expense is cash-paid, the amount is <= 0, the cash account is invalid, or the amount exceeds what's still owed.
     */
    public function recordPayment(array $data): ExpensePayment
    {
        $expense = Expense::findOrFail($data['expense_id']);

        if ($expense->payment_method !== 'credit') {
            throw new InvalidArgumentException('Beban ini dibayar tunai -- tidak ada hutang untuk dilunasi.');
        }

        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Jumlah pembayaran harus lebih besar dari nol.');
        }

        $cashAccountCode = $data['cash_account_code'] ?? CashAccountService::DEFAULT_CODE;
        $this->cashAccounts->assertValidCashAccount($cashAccountCode);

        $status = $this->payableReport->expenseStatus($expense);

        if (bccomp($amount, $status['remaining'], self::SCALE) > 0) {
            throw new InvalidArgumentException(
                "Jumlah pembayaran ({$amount}) melebihi sisa hutang beban ini ({$status['remaining']})."
            );
        }

        return DB::transaction(function () use ($data, $expense, $amount, $cashAccountCode) {
            $payment = new ExpensePayment([
                'outlet_id' => $data['outlet_id'],
                'expense_id' => $expense->id,
                'date' => $data['date'],
                'amount' => $amount,
                'cash_account_code' => $cashAccountCode,
                'memo' => $data['memo'] ?? null,
            ]);
            $payment->save();

            $this->posting->post(
                lines: [
                    ['account' => self::ACCOUNT_HUTANG_BEBAN, 'debit' => $amount, 'credit' => 0],
                    ['account' => $cashAccountCode, 'debit' => 0, 'credit' => $amount],
                ],
                date: $data['date'],
                source: $payment,
                memo: "Pembayaran hutang beban #{$expense->id}: {$expense->description}",
            );

            return $payment->fresh();
        });
    }
}
