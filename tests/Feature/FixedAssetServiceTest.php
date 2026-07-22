<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\FixedAssetService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FixedAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    private FixedAssetService $fixedAssets;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->fixedAssets = new FixedAssetService(new PostingService(), new CashAccountService());
        $this->outlet = Outlet::first();
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'outlet_id' => $this->outlet->id,
            'name' => 'Kulkas Sanken',
            'category' => 'Peralatan',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 12000000,
            'residual_value' => 0,
            'useful_life_months' => 48,
            'payment_method' => 'cash',
        ], $overrides);
    }

    public function test_cash_purchase_posts_a_balanced_journal_debiting_aset_tetap_crediting_kas(): void
    {
        $asset = $this->fixedAssets->recordPurchase($this->baseData());

        $this->assertInstanceOf(FixedAsset::class, $asset);
        $this->assertSame(0, bccomp($asset->acquisition_cost, '12000000', 4));
        $this->assertSame('1-1000', $asset->cash_account_code);

        $journal = Journal::where('source_type', FixedAsset::class)->where('source_id', $asset->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-2000']->debit, '12000000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '12000000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_cash_purchase_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $asset = $this->fixedAssets->recordPurchase($this->baseData(['cash_account_code' => '1-1100']));

        $journal = Journal::where('source_type', FixedAsset::class)->where('source_id', $asset->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->credit, '12000000', 4));
        $this->assertArrayNotHasKey('1-1000', $lines->all());
    }

    public function test_credit_purchase_posts_a_balanced_journal_debiting_aset_tetap_crediting_hutang_lain_lain(): void
    {
        $asset = $this->fixedAssets->recordPurchase($this->baseData(['payment_method' => 'credit']));

        $journal = Journal::where('source_type', FixedAsset::class)->where('source_id', $asset->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-2000']->debit, '12000000', 4));
        $this->assertSame(0, bccomp($lines['2-9000']->credit, '12000000', 4));
        // Terpisah dari Hutang Usaha supplier & Hutang Beban.
        $this->assertFalse($lines->has('2-1000'));
        $this->assertFalse($lines->has('2-2000'));
    }

    public function test_rejects_a_zero_acquisition_cost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fixedAssets->recordPurchase($this->baseData(['acquisition_cost' => 0]));
    }

    public function test_rejects_a_residual_value_greater_than_or_equal_to_cost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fixedAssets->recordPurchase($this->baseData(['acquisition_cost' => 1000000, 'residual_value' => 1000000]));
    }

    public function test_rejects_a_negative_residual_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fixedAssets->recordPurchase($this->baseData(['residual_value' => -1]));
    }

    public function test_rejects_zero_useful_life_months(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fixedAssets->recordPurchase($this->baseData(['useful_life_months' => 0]));
    }

    public function test_book_value_and_monthly_depreciation_amount_are_computed_correctly(): void
    {
        // (12.000.000 - 0) / 48 bulan = 250.000/bulan.
        $asset = $this->fixedAssets->recordPurchase($this->baseData());

        $this->assertSame(0, bccomp($this->fixedAssets->monthlyDepreciationAmount($asset), '250000', 4));
        $this->assertSame(0, bccomp($this->fixedAssets->accumulatedDepreciation($asset), '0', 4));
        $this->assertSame(0, bccomp($this->fixedAssets->bookValue($asset), '12000000', 4));
        $this->assertSame(0, bccomp($this->fixedAssets->remainingDepreciable($asset), '12000000', 4));
    }

    // Prinsip #5: alur multi-langkah gagal -> tidak ada baris yang tersisa.
    public function test_invalid_purchase_does_not_create_any_rows(): void
    {
        try {
            $this->fixedAssets->recordPurchase($this->baseData(['acquisition_cost' => 0]));
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(0, Journal::count());
    }
}
