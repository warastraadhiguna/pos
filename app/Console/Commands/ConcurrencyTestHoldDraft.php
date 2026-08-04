<?php

namespace App\Console\Commands;

use App\Models\Draft;
use App\Models\User;
use App\Services\DraftSyncService;
use Illuminate\Console\Command;

/**
 * Test-support tooling only — NOT a real application feature.
 *
 * Spawned as a genuinely separate OS process by
 * tests/Feature/Concurrency/DraftSyncConcurrencyTest to race
 * DraftSyncService::hold() against the main test process's own call on the
 * SAME unheld draft, from two independent MySQL connections. Unlike
 * ConcurrencyTestHoldInventoryLock (which holds a transaction open for a
 * controlled duration to prove a SECOND caller blocks on a row lock),
 * hold() is a single atomic conditional UPDATE with no transaction held
 * open — so there is nothing to "hold". Instead this command emits READY
 * as soon as it's about to call hold(), then the PARENT test (already
 * waiting on that marker) fires its own hold() call immediately, giving
 * both connections a genuine, timing-independent chance to race the same
 * UPDATE...WHERE — the correctness property under test ("at most one
 * succeeds") comes from InnoDB's row-level locking during that UPDATE's own
 * WHERE evaluation, not from precise simultaneity.
 */
class ConcurrencyTestHoldDraft extends Command
{
    protected $signature = 'concurrency-test:hold-draft
        {draftId : Draft ID}
        {userId : User ID claiming the lock}
        {deviceLabel : Device label to record}';

    protected $hidden = true;

    protected $description = 'Test-support only: races DraftSyncService::hold() against the parent test process.';

    public function handle(DraftSyncService $drafts): int
    {
        if (! app()->environment('testing')) {
            $this->error('This command only runs in the testing environment.');

            return self::FAILURE;
        }

        $draft = Draft::findOrFail($this->argument('draftId'));
        $user = User::findOrFail($this->argument('userId'));

        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);

        try {
            $drafts->hold($draft, $user, $this->argument('deviceLabel'));
            fwrite(STDOUT, "HELD\n");
        } catch (\App\Exceptions\DraftLockedException) {
            fwrite(STDOUT, "REJECTED\n");
        }
        fflush(STDOUT);

        return self::SUCCESS;
    }
}
