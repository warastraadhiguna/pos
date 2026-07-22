<?php

namespace App\Console\Commands;

use App\Services\PostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Test-support tooling only — NOT a real application feature.
 *
 * Spawned as a genuinely separate OS process by
 * tests/Feature/Concurrency/PostingServiceConcurrencyTest to hold the
 * journal_number_sequences row lock (via PostingService::post()) open for a
 * controlled duration, so the test can prove a second, concurrent post()
 * call from the main test process actually blocks on it and never receives
 * a duplicate number — see that test class for the full design rationale.
 */
class ConcurrencyTestHoldJournalLock extends Command
{
    protected $signature = 'concurrency-test:hold-journal-lock
        {accountDrCode : Account code to debit}
        {accountCrCode : Account code to credit}
        {amount : Amount to post}
        {sourceModel : Fully-qualified class name of the polymorphic source model}
        {sourceId : ID of the source model}
        {date : Journal date (Y-m-d)}
        {memo : Journal memo}
        {sleepSeconds : Seconds to hold the lock after writing, before committing}';

    protected $hidden = true;

    protected $description = 'Test-support only: holds a PostingService journal-number lock open for a controlled duration.';

    public function handle(PostingService $posting): int
    {
        if (! app()->environment('testing')) {
            $this->error('This command only runs in the testing environment.');

            return self::FAILURE;
        }

        $sourceModelClass = $this->argument('sourceModel');
        $source = $sourceModelClass::findOrFail($this->argument('sourceId'));

        DB::transaction(function () use ($posting, $source) {
            $posting->post(
                lines: [
                    ['account' => $this->argument('accountDrCode'), 'debit' => $this->argument('amount'), 'credit' => 0],
                    ['account' => $this->argument('accountCrCode'), 'debit' => 0, 'credit' => $this->argument('amount')],
                ],
                date: $this->argument('date'),
                source: $source,
                memo: $this->argument('memo'),
            );

            fwrite(STDOUT, "LOCK_HELD\n");
            fflush(STDOUT);

            sleep((int) $this->argument('sleepSeconds'));
        });

        fwrite(STDOUT, "DONE\n");
        fflush(STDOUT);

        return self::SUCCESS;
    }
}
