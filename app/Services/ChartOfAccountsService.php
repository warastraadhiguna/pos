<?php

namespace App\Services;

use App\Models\Account;
use InvalidArgumentException;

/**
 * Menambah akun BARU di luar rentang yang sudah dikelola halaman khusus
 * (Kelola Akun Beban, Kelola Akun Kas & Bank), dan menjaga akun sistem
 * dari nonaktif tidak sengaja. Halaman Chart of Accounts adalah daftar
 * UTAMA (lihat semua akun + saldo); dua halaman khusus di atas tetap ada
 * berdampingan untuk alur cepatnya masing-masing (validasi rentang kode +
 * integrasi picker Kas/Bank atau Beban) -- lihat dokumen rancangan.
 */
class ChartOfAccountsService
{
    /**
     * Rentang kode yang sudah dikelola alur lain -- form "Tambah Akun" di
     * sini menolak kode manapun yang berawalan ini, supaya tidak ada dua
     * cara berbeda untuk menambah jenis akun yang sama, dan supaya kode
     * yang dipakai FinancialReportService untuk mengelompokkan Laba Rugi
     * (COGS_ACCOUNT_CODE_PREFIXES = 5-1x/5-2x) tidak pernah kebobolan akun
     * baru yang tidak dimaksudkan masuk ke sana.
     *
     * @var array<string, ?string> prefix => nama halaman khusus (null = direservasi penuh, tidak ada halaman pengganti)
     */
    private const PROTECTED_PREFIXES = [
        '1-1' => 'Kelola Akun Kas & Bank',
        '5-1' => null,
        '5-2' => null,
        '5-3' => 'Kelola Akun Beban',
    ];

    /**
     * Kode akun yang di-hardcode LANGSUNG oleh logika inti di berbagai
     * Service -- menonaktifkannya akan merusak posting/laporan. WAJIB
     * diperbarui setiap kali fitur baru men-hardcode kode akun baru.
     */
    private const PROTECTED_CODES = [
        '1-1',      // header grup Kas & Bank (CashAccountService::GROUP_HEADER_CODE)
        '1-1000',   // Kas -- default akun kas (CashAccountService::DEFAULT_CODE)
        '1-1200',   // Persediaan (SaleService, PurchaseService)
        '1-1300',   // PPN Masukan (PurchaseService, TaxRate seed)
        '1-2000',   // Aset Tetap (FixedAssetService)
        '1-2900',   // Akumulasi Penyusutan (DepreciationService)
        '2-1000',   // Hutang Usaha (PurchaseService, SupplierPaymentService)
        '2-1100',   // PPN Keluaran (SaleService, TaxRate seed)
        '2-2000',   // Hutang Beban (ExpenseService, ExpensePaymentService)
        '2-9000',   // Hutang Lain-lain (FixedAssetService, FixedAssetPaymentService)
        '3-1000',   // Modal Pemilik (EquityTransactionService)
        '3-2000',   // Prive (EquityTransactionService)
        '4-1000',   // Penjualan (SaleService)
        '5-1000',   // HPP (SaleService)
        '5-2000',   // Selisih Persediaan (StockOpnameService)
        '5-4000',   // Beban Penyusutan (DepreciationService)
    ];

    /**
     * Setiap akun yang sudah ada mengikuti pasangan tipe<->normal_balance
     * standar TANPA KECUALI (diverifikasi terhadap seluruh 15 akun yang
     * ada saat fitur ini dibangun) -- jadi normal_balance di form "Tambah
     * Akun" SELALU mengikuti tipe, bukan pilihan bebas. Ini menghilangkan
     * seluruh kelas kesalahan "tipe & normal_balance tidak sesuai" karena
     * kombinasi yang salah tidak mungkin dibuat lewat form ini sama sekali.
     */
    private const TYPE_NORMAL_BALANCE = [
        'asset' => 'debit',
        'liability' => 'credit',
        'equity' => 'credit',
        'revenue' => 'credit',
        'expense' => 'debit',
    ];

    private const TYPE_LEADING_DIGIT = [
        'asset' => '1',
        'liability' => '2',
        'equity' => '3',
        'revenue' => '4',
        'expense' => '5',
    ];

    /**
     * @param  array{code: string, name: string, type: string, parent_id?: ?int}  $data
     *
     * @throws InvalidArgumentException
     */
    public function createAccount(array $data): Account
    {
        $code = trim($data['code']);
        $type = $data['type'];

        if (! array_key_exists($type, self::TYPE_NORMAL_BALANCE)) {
            throw new InvalidArgumentException("Tipe akun [{$type}] tidak dikenal.");
        }

        if (! preg_match('/^\d-\d{3,4}$/', $code)) {
            throw new InvalidArgumentException('Format kode harus seperti "1-2100" (satu digit kategori, tanda hubung, 3-4 digit angka).');
        }

        if ($code[0] !== self::TYPE_LEADING_DIGIT[$type]) {
            $expectedDigit = self::TYPE_LEADING_DIGIT[$type];
            throw new InvalidArgumentException("Kode akun tipe [{$type}] harus berawalan \"{$expectedDigit}-\".");
        }

        foreach (self::PROTECTED_PREFIXES as $prefix => $pageName) {
            if (str_starts_with($code, $prefix)) {
                $hint = $pageName
                    ? " Gunakan halaman \"{$pageName}\" untuk menambah akun di rentang ini."
                    : ' Rentang ini direservasi sepenuhnya untuk posting sistem, tidak bisa ditambah manual.';

                throw new InvalidArgumentException("Kode [{$code}] berada di rentang yang sudah dikelola.{$hint}");
            }
        }

        return Account::create([
            'code' => $code,
            'name' => $data['name'],
            'type' => $type,
            'normal_balance' => self::TYPE_NORMAL_BALANCE[$type],
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * @throws InvalidArgumentException if deactivating a protected system account.
     */
    public function setActive(Account $account, bool $active): Account
    {
        if (! $active && $this->isProtected($account->code)) {
            throw new InvalidArgumentException(
                "Akun [{$account->code}] adalah akun sistem yang dipakai langsung oleh logika inti -- tidak bisa dinonaktifkan."
            );
        }

        $account->update(['is_active' => $active]);

        return $account->fresh();
    }

    public function isProtected(string $code): bool
    {
        return in_array($code, self::PROTECTED_CODES, true);
    }
}
