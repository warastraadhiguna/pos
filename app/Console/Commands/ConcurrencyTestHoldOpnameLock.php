<?php

namespace App\Console\Commands;

use App\Models\StockOpname;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Test-support tooling only — NOT a real application feature.
 *
 * Spawned as a genuinely separate OS process by
 * tests/Feature/Concurrency/StockOpnameServiceConcurrencyTest to hold a real
 * MySQL row lock on a `stock_opnames` row open for a controlled duration —
 * simulating "another process is mid-way through posting this exact same
 * opname" (double-click / network retry) — so the test can prove a second,
 * concurrent StockOpnameService::postOpname() call for the SAME opname
 * genuinely blocks on it and then correctly sees the now-`completed` status
 * once this process commits, rather than both racing past the status check.
 *
 * Deliberately replicates only the CRITICAL SECTION (lock row, check status,
 * hold, flip to completed) rather than going through the full
 * StockOpnameService::postOpname() — same reasoning as
 * ConcurrencyTestHoldInventoryLock calling InventoryService::recordInbound()
 * directly instead of routing through SaleService: this command's only job
 * is to hold the specific lock open, not to replicate business logic.
 */
class ConcurrencyTestHoldOpnameLock extends Command
{
    protected $signature = 'concurrency-test:hold-opname-lock
        {opnameId : StockOpname ID}
        {sleepSeconds : Seconds to hold the lock after acquiring, before committing}';

    protected $hidden = true;

    protected $description = 'Test-support only: holds a StockOpname row lock open for a controlled duration.';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('This command only runs in the testing environment.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            $opname = StockOpname::query()->whereKey($this->argument('opnameId'))->lockForUpdate()->firstOrFail();

            if ($opname->status !== 'draft') {
                throw new RuntimeException("Opname #{$opname->id} was not draft when this process acquired the lock.");
            }

            // Signal to the parent test process that the row lock is now
            // held — flush immediately, don't rely on PHP's default output
            // buffering.
            fwrite(STDOUT, "LOCK_HELD\n");
            fflush(STDOUT);

            sleep((int) $this->argument('sleepSeconds'));

            $opname->update(['status' => 'completed']);
        });

        fwrite(STDOUT, "DONE\n");
        fflush(STDOUT);

        return self::SUCCESS;
    }
}
