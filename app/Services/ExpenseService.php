<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Expense;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExpenseService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by
    // Database\Seeders\FoundationSeeder / the 2026_07_21_170000 migration.
    private const ACCOUNT_HUTANG_BEBAN = '2-2000';

    /**
     * HPP dan Selisih Persediaan diposting eksklusif oleh
     * PurchaseService/StockOpnameService; Beban Penyusutan diposting
     * eksklusif oleh DepreciationService -- ketiganya tidak pernah boleh
     * jadi target pencatatan beban manual (itu akan mengacaukan
     * perhitungannya masing-masing di Laba Rugi).
     */
    private const RESERVED_SYSTEM_EXPENSE_CODES = ['5-1000', '5-2000', '5-4000'];

    public function __construct(
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Catat satu transaksi beban operasional -- tunai (Dr Beban / Cr
     * {cash_account_code}) atau kredit (Dr Beban / Cr Hutang Beban),
     * persis pola PurchaseService::postGoodsReceiptJournal() (sisi debit
     * tetap, hanya akun kredit yang bercabang berdasarkan payment_method).
     *
     * @param  array{
     *     outlet_id: int,
     *     expense_account_id: int,
     *     created_by_user_id?: ?int,
     *     date: DateTimeInterface|string,
     *     amount: int|float|string,
     *     payment_method: string,
     *     cash_account_code?: ?string,
     *     description: string,
     *     payee?: ?string,
     * }  $data
     *
     * cash_account_code -- akun Kas/Bank yang menerima/membayar (lihat
     * CashAccountService), hanya relevan/divalidasi saat payment_method
     * 'cash'; diabaikan sepenuhnya saat 'credit'. Default Kas kalau
     * kosong.
     *
     * @throws InvalidArgumentException if the account isn't a selectable expense account, the cash account is invalid, or amount <= 0.
     */
    public function recordExpense(array $data): Expense
    {
        $account = Account::findOrFail($data['expense_account_id']);
        $this->assertSelectableExpenseAccount($account);

        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Jumlah beban harus lebih besar dari nol.');
        }

        $cashAccountCode = $data['cash_account_code'] ?? CashAccountService::DEFAULT_CODE;

        if ($data['payment_method'] === 'cash') {
            $this->cashAccounts->assertValidCashAccount($cashAccountCode);
        }

        return DB::transaction(function () use ($data, $amount, $account, $cashAccountCode) {
            $expense = new Expense([
                'outlet_id' => $data['outlet_id'],
                'expense_account_id' => $account->id,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'cash_account_code' => $cashAccountCode,
                'description' => $data['description'],
                'payee' => $data['payee'] ?? null,
            ]);
            $expense->save();

            $this->postExpenseJournal($expense, $account, $amount, $data['date'], $data['payment_method'], $cashAccountCode);

            return $expense->fresh();
        });
    }

    private function postExpenseJournal(Expense $expense, Account $account, string $amount, DateTimeInterface|string $date, string $paymentMethod, string $cashAccountCode): void
    {
        // Tunai -> akun Kas/Bank pilihan berkurang di tempat. Kredit ->
        // Hutang Beban (akun TERPISAH dari Hutang Usaha 2-1000 -- lihat
        // dokumentasi rancangan: mencampur hutang beban dengan hutang
        // supplier akan salah klasifikasi akuntansi dan berisiko
        // mencemari Laporan Hutang Supplier).
        $creditAccount = match ($paymentMethod) {
            'cash' => $cashAccountCode,
            'credit' => self::ACCOUNT_HUTANG_BEBAN,
            default => throw new InvalidArgumentException("Unknown payment method [{$paymentMethod}]."),
        };

        $this->posting->post(
            lines: [
                ['account' => $account, 'debit' => $amount, 'credit' => 0],
                ['account' => $creditAccount, 'debit' => 0, 'credit' => $amount],
            ],
            date: $date,
            source: $expense,
            memo: "Beban: {$expense->description}",
        );
    }

    /**
     * Only active `type=expense` accounts outside the two system-reserved
     * codes may be targeted by a manual expense entry.
     *
     * @throws InvalidArgumentException
     */
    public function assertSelectableExpenseAccount(Account $account): void
    {
        if ($account->type !== 'expense') {
            throw new InvalidArgumentException("Akun [{$account->code}] bukan akun beban.");
        }

        if (in_array($account->code, self::RESERVED_SYSTEM_EXPENSE_CODES, true)) {
            throw new InvalidArgumentException("Akun [{$account->code}] direservasi untuk posting sistem, tidak bisa dipakai untuk beban manual.");
        }

        if (! $account->is_active) {
            throw new InvalidArgumentException("Akun [{$account->code}] sudah dinonaktifkan.");
        }
    }

    /**
     * Akun beban yang boleh ditampilkan di dropdown form Catat Beban.
     *
     * @return array<int, Account>
     */
    public function selectableExpenseAccounts(): array
    {
        return Account::where('type', 'expense')
            ->where('is_active', true)
            ->whereNotIn('code', self::RESERVED_SYSTEM_EXPENSE_CODES)
            ->orderBy('code')
            ->get()
            ->all();
    }

    /**
     * UI CoA minimal (khusus akun beban): admin hanya bisa MENAMBAH akun
     * baru, tidak pernah edit/hapus -- akun yang sudah dipakai di jurnal
     * tidak boleh berubah maknanya. Kode wajib berawalan "5-3" supaya
     * tidak pernah bentrok dengan rentang sistem 5-1xxx/5-2xxx yang
     * dipakai FinancialReportService untuk memisahkan Laba Kotor dari
     * Beban Operasional.
     *
     * @throws InvalidArgumentException
     */
    public function createExpenseAccount(string $code, string $name): Account
    {
        if (! str_starts_with($code, '5-3')) {
            throw new InvalidArgumentException('Kode akun beban baru harus berawalan "5-3".');
        }

        return Account::create([
            'code' => $code,
            'name' => $name,
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
    }

    /**
     * Nonaktifkan/aktifkan kembali akun beban -- pengganti "hapus" yang
     * aman terhadap histori jurnal (akun yang sudah dipakai tetap ada,
     * cuma berhenti muncul di pilihan form baru).
     *
     * @throws InvalidArgumentException
     */
    public function setExpenseAccountActive(Account $account, bool $active): Account
    {
        if ($account->type !== 'expense') {
            throw new InvalidArgumentException("Akun [{$account->code}] bukan akun beban.");
        }

        $account->update(['is_active' => $active]);

        return $account->fresh();
    }
}
