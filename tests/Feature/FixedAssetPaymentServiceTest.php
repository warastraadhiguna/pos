<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\FixedAssetPayment;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\FixedAssetPayableReportService;
use App\Services\FixedAssetPaymentService;
use App\Services\FixedAssetService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FixedAssetPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private FixedAssetService $fixedAssets;

    private FixedAssetPaymentService $payments;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $cashAccounts = new CashAccountService();
        $this->fixedAssets = new FixedAssetService(new PostingService(), $cashAccounts);
        $this->payments = new FixedAssetPaymentService(new PostingService(), new FixedAssetPayableReportService(), $cashAccounts);
        $this->outlet = Outlet::first();
    }

    private function creditAsset(string $cost): FixedAsset
    {
        return $this->fixedAssets->recordPurchase([
            'outlet_id' => $this->outlet->id,
            'name' => 'Mesin Kredit',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => $cost,
            'residual_value' => 0,
            'useful_life_months' => 24,
            'payment_method' => 'credit',
        ]);
    }

    public function test_recording_a_payment_posts_a_balanced_journal_debiting_hutang_lain_lain_crediting_kas(): void
    {
        $asset = $this->creditAsset('5000000');

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'fixed_asset_id' => $asset->id,
            'date' => '2026-02-10',
            'amount' => 5000000,
        ]);

        $this->assertInstanceOf(FixedAssetPayment::class, $payment);

        $journal = Journal::where('source_type', FixedAssetPayment::class)->where('source_id', $payment->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['2-9000']->debit, '5000000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '5000000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_recording_a_payment_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $asset = $this->creditAsset('2000000');

        $payment = $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'fixed_asset_id' => $asset->id,
            'date' => '2026-02-10',
            'amount' => 2000000,
            'cash_account_code' => '1-1100',
        ]);

        $journal = Journal::where('source_type', FixedAssetPayment::class)->where('source_id', $payment->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->credit, '2000000', 4));
        $this->assertArrayNotHasKey('1-1000', $lines->all());
    }

    public function test_partial_payment_leaves_a_remaining_balance_with_status_sebagian(): void
    {
        $asset = $this->creditAsset('5000000');

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'fixed_asset_id' => $asset->id,
            'date' => '2026-02-10',
            'amount' => 2000000,
        ]);

        $status = (new FixedAssetPayableReportService())->assetStatus($asset->fresh());
        $this->assertSame(0, bccomp($status['remaining'], '3000000', 4));
        $this->assertSame('sebagian', $status['status']);
    }

    public function test_rejects_payment_exceeding_the_remaining_balance(): void
    {
        $asset = $this->creditAsset('5000000');

        $this->expectException(InvalidArgumentException::class);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'fixed_asset_id' => $asset->id,
            'date' => '2026-02-10',
            'amount' => 6000000,
        ]);
    }

    public function test_rejects_payment_for_a_cash_asset(): void
    {
        $asset = $this->fixedAssets->recordPurchase([
            'outlet_id' => $this->outlet->id,
            'name' => 'Mesin Tunai',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 1000000,
            'residual_value' => 0,
            'useful_life_months' => 12,
            'payment_method' => 'cash',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->payments->recordPayment([
            'outlet_id' => $this->outlet->id,
            'fixed_asset_id' => $asset->id,
            'date' => '2026-02-10',
            'amount' => 100000,
        ]);
    }

    public function test_overpayment_attempt_creates_no_rows(): void
    {
        $asset = $this->creditAsset('5000000');

        try {
            $this->payments->recordPayment([
                'outlet_id' => $this->outlet->id,
                'fixed_asset_id' => $asset->id,
                'date' => '2026-02-10',
                'amount' => 6000000,
            ]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, FixedAssetPayment::count());
        $this->assertSame(1, Journal::where('source_type', FixedAsset::class)->count());
        $this->assertSame(0, Journal::where('source_type', FixedAssetPayment::class)->count());
    }
}
