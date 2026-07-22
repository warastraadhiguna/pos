<?php

namespace Tests\Feature;

use App\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Outlet;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PostingService $posting;

    private static int $seq = 0;

    private Account $kas;

    private Account $penjualan;

    private Account $ppnKeluaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posting = new PostingService();
        $this->kas = $this->makeAccount('TEST-KAS', 'Kas', 'asset', 'debit');
        $this->penjualan = $this->makeAccount('TEST-PNJ', 'Penjualan', 'revenue', 'credit');
        $this->ppnKeluaran = $this->makeAccount('TEST-PPNK', 'PPN Keluaran', 'liability', 'credit');
    }

    public function test_posting_a_balanced_journal_creates_header_and_lines(): void
    {
        $journal = $this->posting->post(
            lines: [
                ['account' => $this->kas, 'debit' => 111000, 'credit' => 0],
                ['account' => 'TEST-PNJ', 'debit' => 0, 'credit' => 100000],
                ['account' => 'TEST-PPNK', 'debit' => 0, 'credit' => 11000],
            ],
            date: '2026-07-04',
            source: $this->makeSource(),
            memo: 'Penjualan tunai',
        );

        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseCount('journal_lines', 3);

        $lines = $journal->lines;
        $sumDebit = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $sumCredit = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');

        $this->assertSame(3, $lines->count());
        $this->assertSame(0, bccomp($sumDebit, '111000', 4));
        $this->assertSame(0, bccomp($sumCredit, '111000', 4));
    }

    public function test_unbalanced_journal_throws_and_persists_nothing(): void
    {
        try {
            $this->posting->post(
                lines: [
                    ['account' => $this->kas, 'debit' => 100, 'credit' => 0],
                    ['account' => $this->penjualan, 'debit' => 0, 'credit' => 90],
                ],
                date: '2026-07-04',
                source: $this->makeSource(),
                memo: 'Tidak seimbang',
            );

            $this->fail('Expected UnbalancedJournalException was not thrown.');
        } catch (UnbalancedJournalException $e) {
            // expected
        }

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_journal_number_is_auto_generated_and_unique_when_not_provided(): void
    {
        $first = $this->posting->post(
            lines: [
                ['account' => $this->kas, 'debit' => 50000, 'credit' => 0],
                ['account' => $this->penjualan, 'debit' => 0, 'credit' => 50000],
            ],
            date: '2026-07-04',
            source: $this->makeSource(),
            memo: 'Transaksi 1',
        );

        $second = $this->posting->post(
            lines: [
                ['account' => $this->kas, 'debit' => 20000, 'credit' => 0],
                ['account' => $this->penjualan, 'debit' => 0, 'credit' => 20000],
            ],
            date: '2026-07-04',
            source: $this->makeSource(),
            memo: 'Transaksi 2',
        );

        $this->assertMatchesRegularExpression('/^JV-202607-\d{4}$/', $first->number);
        $this->assertMatchesRegularExpression('/^JV-202607-\d{4}$/', $second->number);
        $this->assertNotSame($first->number, $second->number);
    }

    public function test_two_sequential_posts_in_a_previously_empty_month_get_gapless_sequential_numbers(): void
    {
        $first = $this->posting->post(
            lines: [
                ['account' => $this->kas, 'debit' => 10000, 'credit' => 0],
                ['account' => $this->penjualan, 'debit' => 0, 'credit' => 10000],
            ],
            date: '2026-10-01',
            source: $this->makeSource(),
            memo: 'Transaksi pertama bulan kosong',
        );

        $second = $this->posting->post(
            lines: [
                ['account' => $this->kas, 'debit' => 20000, 'credit' => 0],
                ['account' => $this->penjualan, 'debit' => 0, 'credit' => 20000],
            ],
            date: '2026-10-15',
            source: $this->makeSource(),
            memo: 'Transaksi kedua bulan yang sama',
        );

        // Bulan Oktober belum pernah dipakai test lain — membuktikan counter
        // naik benar dari nol (bukan sekadar "berbeda", tapi persis 0001/0002).
        $this->assertSame('JV-202610-0001', $first->number);
        $this->assertSame('JV-202610-0002', $second->number);
    }

    public function test_sequence_counter_rolls_back_when_post_fails_for_an_unrelated_reason(): void
    {
        // Sengaja tidak disimpan — getKey() akan null, sehingga
        // $journal->source()->associate() menaruh source_id = null dan
        // INSERT gagal karena kolom itu NOT NULL. Ini kegagalan DI DALAM
        // transaksi, SETELAH generateNumber() sempat menaikkan counter —
        // pas untuk membuktikan increment ikut rollback.
        $unsavedSource = new Outlet(['name' => 'Unsaved Source']);

        try {
            $this->posting->post(
                lines: [
                    ['account' => $this->kas, 'debit' => 1000, 'credit' => 0],
                    ['account' => $this->penjualan, 'debit' => 0, 'credit' => 1000],
                ],
                date: '2026-09-01',
                source: $unsavedSource,
                memo: 'Percobaan gagal karena source belum tersimpan',
            );

            $this->fail('Expected a database constraint violation was not thrown.');
        } catch (\Illuminate\Database\QueryException $e) {
            // diharapkan — pelanggaran NOT NULL pada source_id, bukan
            // duplicate number, jadi tidak boleh di-retry sama sekali.
        }

        $this->assertDatabaseMissing('journal_number_sequences', ['period' => '202609']);
        $this->assertSame(0, Journal::where('number', 'like', 'JV-202609-%')->count());
    }

    public function test_resolve_account_accepts_a_code_and_returns_the_matching_account(): void
    {
        $resolved = $this->posting->resolveAccount('TEST-PPNK');

        $this->assertTrue($resolved->is($this->ppnKeluaran));
        $this->assertSame('PPN Keluaran', $resolved->name);
    }

    private function makeAccount(string $code, string $name, string $type, string $normalBalance): Account
    {
        return Account::create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
        ]);
    }

    private function makeSource(): Outlet
    {
        return Outlet::create(['name' => 'Source '.(++self::$seq)]);
    }
}
