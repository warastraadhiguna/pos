<?php

namespace Tests\Feature\Concurrency;

use App\Models\Account;
use App\Models\Journal;
use App\Models\Outlet;
use App\Services\PostingService;
use Illuminate\Support\Facades\DB;

class PostingServiceConcurrencyTest extends ConcurrencyTestCase
{
    // Bulan yang jauh dari data lain manapun (masa lalu/kini), supaya
    // dijamin benar-benar kosong di awal test — ini persis skenario yang
    // sebelumnya rawan race (tidak ada baris journals untuk dikunci) dan
    // sekarang aman berkat journal_number_sequences.
    private const TEST_DATE = '2031-03-15';

    private const NUMBER_PREFIX = 'JV-203103-';

    private const PERIOD = '203103';

    private PostingService $posting;

    private Account $debitAccount;

    private Account $creditAccount;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posting = new PostingService();

        $suffix = uniqid('cct_');
        $this->debitAccount = Account::create([
            'code' => 'DR-'.$suffix,
            'name' => 'Concurrency Test Debit',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);
        $this->creditAccount = Account::create([
            'code' => 'CR-'.$suffix,
            'name' => 'Concurrency Test Credit',
            'type' => 'liability',
            'normal_balance' => 'credit',
        ]);
        $this->outlet = Outlet::create(['name' => 'Concurrency Test Outlet '.$suffix]);

        // Defensive: kalau run sebelumnya pernah crash sebelum tearDown()
        // sempat jalan, bersihkan dulu supaya test ini tidak flaky karena
        // sisa data lama.
        $this->purgeTestPeriodData();
    }

    protected function tearDown(): void
    {
        $this->purgeTestPeriodData();

        $this->outlet->delete();
        $this->creditAccount->delete();
        $this->debitAccount->delete();

        parent::tearDown();
    }

    public function test_two_concurrent_posts_in_a_previously_empty_month_never_collide(): void
    {
        $holdSeconds = 3;

        // Proses A ("pemegang lock") — subprocess OS sungguhan. post()
        // dengan $number=null akan memanggil generateNumber(), yang kini
        // meng-upsert baris journal_number_sequences untuk periode ini
        // (menjaminnya ADA) sebelum mengunci baris itu — persis kasus
        // "jurnal pertama di bulan kosong" yang sebelumnya rawan race.
        $processA = $this->spawnArtisan([
            'concurrency-test:hold-journal-lock',
            $this->debitAccount->code,
            $this->creditAccount->code,
            '1000',
            Outlet::class,
            (string) $this->outlet->id,
            self::TEST_DATE,
            'Concurrency test A',
            (string) $holdSeconds,
        ]);

        $this->waitForMarker($processA, 'LOCK_HELD');

        // Proses B ("penunggu") — proses test utama, memanggil post()
        // secara normal. Wajib ter-block pada baris counter yang sama.
        $start = microtime(true);
        $journalB = $this->posting->post(
            lines: [
                ['account' => $this->debitAccount, 'debit' => 2000, 'credit' => 0],
                ['account' => $this->creditAccount, 'debit' => 0, 'credit' => 2000],
            ],
            date: self::TEST_DATE,
            source: $this->outlet,
            memo: 'Concurrency test B',
        );
        $elapsed = microtime(true) - $start;

        $result = $processA->wait();
        $this->assertTrue($result->successful(), 'Subprocess A gagal: '.$result->errorOutput());

        // Bukti #1: B benar-benar menunggu lock A, bukan kebetulan urutan.
        $this->assertGreaterThanOrEqual(
            $holdSeconds - 1,
            $elapsed,
            'post() di proses B seharusnya ter-block oleh lock yang dipegang proses A.',
        );

        $journalA = Journal::where('memo', 'Concurrency test A')->firstOrFail();

        // Bukti #2: nomor tidak pernah duplikat, dan urutannya deterministik
        // (A pasti selesai lebih dulu karena B menunggu lock A) — A dapat
        // 0001, B dapat 0002. Ini bulan yang TADINYA KOSONG sama sekali.
        $this->assertNotSame($journalA->number, $journalB->number);
        $this->assertSame(self::NUMBER_PREFIX.'0001', $journalA->number);
        $this->assertSame(self::NUMBER_PREFIX.'0002', $journalB->number);

        $this->assertSame(2, Journal::where('number', 'like', self::NUMBER_PREFIX.'%')->count());
    }

    private function purgeTestPeriodData(): void
    {
        Journal::where('number', 'like', self::NUMBER_PREFIX.'%')->get()->each(function (Journal $journal) {
            $journal->lines()->delete();
            $journal->delete();
        });

        DB::table('journal_number_sequences')->where('period', self::PERIOD)->delete();
    }
}
