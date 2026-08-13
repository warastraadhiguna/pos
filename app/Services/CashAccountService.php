<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Outlet;
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
     * Same as selectableCashAccounts() but excludes Kas -- for pickers that
     * only ever make sense pointed at a BANK account specifically: the
     * QRIS default account in Pengaturan, and the "Masuk Ke" picker in
     * Kasir web when payment_method=qris (see rancangan fitur QRIS,
     * SaleService::createSale()). Kas is always selectableCashAccounts()'s
     * first row (code '1-1000' sorts first alphabetically among '1-1xxx'
     * codes) -- filtered out by code rather than by array position so this
     * stays correct even if that ordering assumption ever changes.
     *
     * @return array<int, Account>
     */
    public function selectableBankAccounts(): array
    {
        return array_values(array_filter(
            $this->selectableCashAccounts(),
            fn (Account $account) => $account->code !== self::DEFAULT_CODE,
        ));
    }

    /**
     * @throws InvalidArgumentException if $code isn't an active child of the Kas & Bank group.
     */
    public function assertValidCashAccount(string $code): void
    {
        $account = Account::where('code', $code)->first();

        // (int) cast eksplisit di SINI, di atas cast 'parent_id' => 'integer'
        // yang sudah ada di model Account -- dua lapis sengaja, bukan
        // redundan: ini jalur validasi yang MENOLAK transaksi penjualan
        // asli kalau salah (insiden produksi "Akun [1-1000] bukan Kas/Bank
        // yang aktif" — data benar, tapi parent_id kebetulan kembali
        // sebagai string dari driver PDO tertentu, `!==` gagal). Cast di
        // sini memastikan perbandingan tetap aman walau suatu saat model
        // Account diinstansiasi lewat jalur yang tidak melalui cast biasa.
        if (! $account || (int) $account->parent_id !== $this->groupHeaderId() || ! $account->is_active) {
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
     * `$outletId` (Multi-Cabang Lapisan 1) -- opsional, null berarti akun
     * global/bersama (perilaku hari ini, tidak berubah). Diisi berarti
     * akun ini representasi laci/kas milik satu cabang tertentu. Belum
     * di-enforce di alur transaksi mana pun (Lapisan 2/3), Lapisan 1 cuma
     * menyimpan penugasannya.
     *
     * @throws InvalidArgumentException
     */
    public function createBankAccount(string $code, string $name, ?int $outletId = null): Account
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
            'outlet_id' => $outletId,
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
        if ((int) $account->parent_id !== $this->groupHeaderId()) {
            throw new InvalidArgumentException("Akun [{$account->code}] bukan akun Kas/Bank.");
        }

        $account->update(['is_active' => $active]);

        return $account->fresh();
    }

    /**
     * Multi-Cabang Lapisan 3 -- akun Kas/Bank tunai penjualan CABANG itu
     * seharusnya mendarat. Cabang PUSAT selalu Kas global (`1-1000`,
     * perilaku hari ini, tidak berubah -- pusat tidak butuh akun
     * ber-outlet_id sendiri). Cabang LAIN: akun Kas/Bank aktif PERTAMA
     * (urut kode) yang `outlet_id`-nya cabang itu (ditugaskan admin lewat
     * "Tambah Akun Bank" + pemilih cabang, Lapisan 1).
     *
     * Cabang yang BELUM PERNAH ditugaskan akun apa pun -> fallback KAS
     * GLOBAL, bukan exception -- rancangan yang disetujui eksplisit
     * meminta "jangan sampai transaksi gagal karena cabang belum punya
     * kas". Ini konsisten dengan bagaimana `resolveCurrentOutlet()` juga
     * tidak pernah melempar, selalu jatuh ke sesuatu yang aman.
     */
    public function resolveCashAccountCodeForOutlet(Outlet $outlet): string
    {
        if ($outlet->is_headquarters) {
            return self::DEFAULT_CODE;
        }

        $account = Account::where('outlet_id', $outlet->id)
            ->where('parent_id', $this->groupHeaderId())
            ->where('is_active', true)
            ->orderBy('code')
            ->first();

        return $account?->code ?? self::DEFAULT_CODE;
    }

    private function groupHeaderId(): int
    {
        return Account::where('code', self::GROUP_HEADER_CODE)->firstOrFail()->id;
    }
}
