<?php

namespace Tests\Feature;

use App\Models\CashTransfer;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\CashTransferService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CashTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashTransferService $transfers;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->transfers = new CashTransferService(new PostingService(), new CashAccountService());
        $this->outlet = Outlet::first();
    }

    public function test_recording_a_transfer_posts_a_balanced_journal_debiting_to_crediting_from(): void
    {
        $transfer = $this->transfers->recordTransfer([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-10',
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1100',
            'amount' => 500000,
            'memo' => 'Setor hasil jualan',
        ]);

        $this->assertInstanceOf(CashTransfer::class, $transfer);
        $this->assertSame(0, bccomp($transfer->amount, '500000', 4));

        $journal = Journal::where('source_type', CashTransfer::class)->where('source_id', $transfer->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->debit, '500000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '500000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_recording_a_transfer_the_other_direction_also_works(): void
    {
        $transfer = $this->transfers->recordTransfer([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-10',
            'from_account_code' => '1-1100',
            'to_account_code' => '1-1000',
            'amount' => 200000,
        ]);

        $journal = Journal::where('source_type', CashTransfer::class)->where('source_id', $transfer->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1000']->debit, '200000', 4));
        $this->assertSame(0, bccomp($lines['1-1100']->credit, '200000', 4));
    }

    public function test_recording_a_transfer_rejects_the_same_account_on_both_sides(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->transfers->recordTransfer([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-10',
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1000',
            'amount' => 100000,
        ]);
    }

    public function test_recording_a_transfer_rejects_an_invalid_from_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->transfers->recordTransfer([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-10',
            'from_account_code' => '1-1200', // Persediaan, bukan akun kas/bank
            'to_account_code' => '1-1100',
            'amount' => 100000,
        ]);
    }

    public function test_recording_a_transfer_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->transfers->recordTransfer([
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-10',
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1100',
            'amount' => 0,
        ]);
    }

    // Prinsip #5: alur multi-langkah gagal -> tidak ada baris yang tersisa.
    public function test_invalid_transfer_does_not_create_any_rows(): void
    {
        try {
            $this->transfers->recordTransfer([
                'outlet_id' => $this->outlet->id,
                'date' => '2026-07-10',
                'from_account_code' => '1-1000',
                'to_account_code' => '1-1000',
                'amount' => 100000,
            ]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, CashTransfer::count());
        $this->assertSame(0, Journal::count());
    }
}
