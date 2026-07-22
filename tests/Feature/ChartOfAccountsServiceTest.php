<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\ChartOfAccountsService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChartOfAccountsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $coa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->coa = new ChartOfAccountsService();
    }

    public function test_create_account_succeeds_with_a_valid_code_outside_all_protected_ranges(): void
    {
        $account = $this->coa->createAccount([
            'code' => '1-2100',
            'name' => 'Deposit Sewa',
            'type' => 'asset',
        ]);

        $this->assertSame('1-2100', $account->code);
        $this->assertSame('asset', $account->type);
        // normal_balance SELALU mengikuti tipe, bukan input bebas.
        $this->assertSame('debit', $account->normal_balance);
        $this->assertTrue($account->is_active);
        $this->assertNull($account->parent_id);
    }

    public function test_create_account_derives_normal_balance_from_type_for_every_type(): void
    {
        $this->assertSame('debit', $this->coa->createAccount(['code' => '1-2101', 'name' => 'A', 'type' => 'asset'])->normal_balance);
        $this->assertSame('credit', $this->coa->createAccount(['code' => '2-9100', 'name' => 'B', 'type' => 'liability'])->normal_balance);
        $this->assertSame('credit', $this->coa->createAccount(['code' => '3-9000', 'name' => 'C', 'type' => 'equity'])->normal_balance);
        $this->assertSame('credit', $this->coa->createAccount(['code' => '4-9000', 'name' => 'D', 'type' => 'revenue'])->normal_balance);
        $this->assertSame('debit', $this->coa->createAccount(['code' => '5-9000', 'name' => 'E', 'type' => 'expense'])->normal_balance);
    }

    public function test_create_account_accepts_an_optional_parent(): void
    {
        $parent = $this->coa->createAccount(['code' => '1-2100', 'name' => 'Grup Deposit', 'type' => 'asset']);
        $child = $this->coa->createAccount(['code' => '1-2101', 'name' => 'Deposit Sewa Toko', 'type' => 'asset', 'parent_id' => $parent->id]);

        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_create_account_rejects_an_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->coa->createAccount(['code' => '1-2100', 'name' => 'X', 'type' => 'unknown']);
    }

    public function test_create_account_rejects_a_malformed_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->coa->createAccount(['code' => 'ABC', 'name' => 'X', 'type' => 'asset']);
    }

    public function test_create_account_rejects_a_code_whose_leading_digit_does_not_match_the_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Kode berawalan "2-" (liability) tapi tipe dipilih asset.
        $this->coa->createAccount(['code' => '2-9999', 'name' => 'X', 'type' => 'asset']);
    }

    public function test_create_account_rejects_the_kas_bank_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->coa->createAccount(['code' => '1-1500', 'name' => 'X', 'type' => 'asset']);
    }

    public function test_create_account_rejects_the_cogs_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->coa->createAccount(['code' => '5-1500', 'name' => 'X', 'type' => 'expense']);
    }

    public function test_create_account_rejects_the_selisih_persediaan_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->coa->createAccount(['code' => '5-2500', 'name' => 'X', 'type' => 'expense']);
    }

    public function test_create_account_rejects_the_beban_operasional_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->coa->createAccount(['code' => '5-3500', 'name' => 'X', 'type' => 'expense']);
    }

    public function test_create_account_allows_the_beban_penyusutan_exact_code_range_5_4_to_be_rejected_only_via_protected_codes_not_prefix(): void
    {
        // 5-4xxx BUKAN rentang yang diblokir prefix (cuma 5-4000 persis
        // yang protected via kode) -- akun BARU lain di 5-4xxx (selain
        // 5-4000 sendiri, yang sudah ada & akan gagal karena unique) tetap
        // boleh dibuat lewat form generik ini.
        $account = $this->coa->createAccount(['code' => '5-4100', 'name' => 'Beban Lain', 'type' => 'expense']);
        $this->assertSame('5-4100', $account->code);
    }

    public function test_set_active_toggles_a_normal_account(): void
    {
        $account = $this->coa->createAccount(['code' => '1-2100', 'name' => 'Deposit Sewa', 'type' => 'asset']);

        $this->coa->setActive($account, false);
        $this->assertFalse($account->fresh()->is_active);

        $this->coa->setActive($account, true);
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_set_active_rejects_deactivating_a_protected_system_account(): void
    {
        $kas = Account::where('code', '1-1000')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        $this->coa->setActive($kas, false);
    }

    #[DataProvider('protectedCodesProvider')]
    public function test_set_active_rejects_deactivating_every_protected_code(string $code): void
    {
        $account = Account::where('code', $code)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        $this->coa->setActive($account, false);
    }

    public static function protectedCodesProvider(): array
    {
        return [
            ['1-1'], ['1-1000'], ['1-1200'], ['1-1300'], ['1-2000'], ['1-2900'],
            ['2-1000'], ['2-1100'], ['2-2000'], ['2-9000'],
            ['3-1000'], ['3-2000'], ['4-1000'], ['5-1000'], ['5-2000'], ['5-4000'],
        ];
    }

    public function test_is_protected_correctly_identifies_system_and_non_system_codes(): void
    {
        $this->assertTrue($this->coa->isProtected('1-1000'));
        $this->assertFalse($this->coa->isProtected('1-2100'));
    }
}
