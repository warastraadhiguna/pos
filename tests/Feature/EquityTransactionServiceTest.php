<?php

namespace Tests\Feature;

use App\Models\EquityTransaction;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\EquityTransactionService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EquityTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private EquityTransactionService $equity;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->equity = new EquityTransactionService(new PostingService(), new CashAccountService());
        $this->outlet = Outlet::first();
    }

    public function test_modal_deposit_posts_a_balanced_journal_debiting_kas_crediting_modal(): void
    {
        $transaction = $this->equity->recordModalDeposit([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 1000000,
            'description' => 'Setoran awal',
        ]);

        $this->assertInstanceOf(EquityTransaction::class, $transaction);
        $this->assertSame('modal', $transaction->type);
        $this->assertSame('1-1000', $transaction->cash_account_code);

        $journal = Journal::where('source_type', EquityTransaction::class)->where('source_id', $transaction->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1000']->debit, '1000000', 4));
        $this->assertSame(0, bccomp($lines['3-1000']->credit, '1000000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_modal_deposit_with_bank_selected_debits_bank_instead_of_kas(): void
    {
        $transaction = $this->equity->recordModalDeposit([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 500000,
            'cash_account_code' => '1-1100',
        ]);

        $journal = Journal::where('source_type', EquityTransaction::class)->where('source_id', $transaction->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->debit, '500000', 4));
        $this->assertSame(0, bccomp($lines['3-1000']->credit, '500000', 4));
        $this->assertArrayNotHasKey('1-1000', $lines->all());
    }

    public function test_prive_withdrawal_posts_a_balanced_journal_debiting_prive_crediting_kas(): void
    {
        $transaction = $this->equity->recordPriveWithdrawal([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 300000,
            'description' => 'Keperluan pribadi',
        ]);

        $this->assertSame('prive', $transaction->type);

        $journal = Journal::where('source_type', EquityTransaction::class)->where('source_id', $transaction->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['3-2000']->debit, '300000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '300000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_prive_withdrawal_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $transaction = $this->equity->recordPriveWithdrawal([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 150000,
            'cash_account_code' => '1-1100',
        ]);

        $journal = Journal::where('source_type', EquityTransaction::class)->where('source_id', $transaction->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['3-2000']->debit, '150000', 4));
        $this->assertSame(0, bccomp($lines['1-1100']->credit, '150000', 4));
        $this->assertArrayNotHasKey('1-1000', $lines->all());
    }

    public function test_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->equity->recordModalDeposit([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 0,
        ]);
    }

    public function test_rejects_an_invalid_cash_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->equity->recordPriveWithdrawal([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-22',
            'amount' => 100000,
            'cash_account_code' => '1-1200', // Persediaan, bukan akun kas/bank
        ]);
    }

    // Prinsip #5: alur multi-langkah gagal -> tidak ada baris yang tersisa.
    public function test_invalid_transaction_does_not_create_any_rows(): void
    {
        try {
            $this->equity->recordModalDeposit([
                'outlet_id' => $this->outlet->id,
                'date' => '2026-07-22',
                'amount' => 0,
            ]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, EquityTransaction::count());
        $this->assertSame(0, Journal::count());
    }
}
