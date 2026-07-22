<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\CashAccountService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CashAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashAccountService $cashAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->cashAccounts = new CashAccountService();
    }

    public function test_selectable_cash_accounts_lists_kas_and_bank_but_not_the_group_header(): void
    {
        $codes = collect($this->cashAccounts->selectableCashAccounts())->pluck('code')->all();

        $this->assertContains('1-1000', $codes);
        $this->assertContains('1-1100', $codes);
        $this->assertNotContains('1-1', $codes); // header, tidak pernah bisa dipilih
    }

    public function test_selectable_cash_accounts_excludes_inactive_accounts(): void
    {
        Account::where('code', '1-1100')->firstOrFail()->update(['is_active' => false]);

        $codes = collect($this->cashAccounts->selectableCashAccounts())->pluck('code')->all();

        $this->assertContains('1-1000', $codes);
        $this->assertNotContains('1-1100', $codes);
    }

    public function test_assert_valid_cash_account_accepts_kas_and_bank(): void
    {
        $this->cashAccounts->assertValidCashAccount('1-1000');
        $this->cashAccounts->assertValidCashAccount('1-1100');

        $this->assertTrue(true); // tidak melempar exception apa pun di atas
    }

    public function test_assert_valid_cash_account_rejects_an_unrelated_asset_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cashAccounts->assertValidCashAccount('1-1200'); // Persediaan
    }

    public function test_assert_valid_cash_account_rejects_the_group_header_itself(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cashAccounts->assertValidCashAccount('1-1');
    }

    public function test_assert_valid_cash_account_rejects_a_nonexistent_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cashAccounts->assertValidCashAccount('9-9999');
    }

    public function test_assert_valid_cash_account_rejects_an_inactive_account(): void
    {
        Account::where('code', '1-1100')->firstOrFail()->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);

        $this->cashAccounts->assertValidCashAccount('1-1100');
    }

    public function test_create_bank_account_requires_the_1_11xx_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cashAccounts->createBankAccount('1-1200', 'Salah Format');
    }

    public function test_create_bank_account_succeeds_and_becomes_a_child_of_the_group_header(): void
    {
        $header = Account::where('code', '1-1')->firstOrFail();

        $account = $this->cashAccounts->createBankAccount('1-1101', 'Bank Mandiri');

        $this->assertSame('1-1101', $account->code);
        $this->assertSame('asset', $account->type);
        $this->assertSame($header->id, $account->parent_id);
        $this->assertTrue($account->is_active);

        // Langsung muncul di daftar pilihan tanpa perubahan kode apa pun.
        $codes = collect($this->cashAccounts->selectableCashAccounts())->pluck('code')->all();
        $this->assertContains('1-1101', $codes);
    }

    public function test_set_cash_account_active_toggles_and_persists(): void
    {
        $bank = Account::where('code', '1-1100')->firstOrFail();

        $this->cashAccounts->setCashAccountActive($bank, false);
        $this->assertFalse($bank->fresh()->is_active);

        $this->cashAccounts->setCashAccountActive($bank, true);
        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_set_cash_account_active_rejects_an_account_outside_the_group(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cashAccounts->setCashAccountActive(Account::where('code', '1-1200')->firstOrFail(), false);
    }
}
