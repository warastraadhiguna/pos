<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Test-support tooling only — NOT a real application feature.
 *
 * Spawned as a genuinely separate OS process by
 * tests/Feature/Concurrency/InventoryServiceConcurrencyTest to hold a real
 * MySQL row lock (via InventoryService::recordInbound()) open for a
 * controlled duration, so the test can prove a second, concurrent call from
 * the main test process actually blocks on it — see that test class for the
 * full design rationale.
 */
class ConcurrencyTestHoldInventoryLock extends Command
{
    protected $signature = 'concurrency-test:hold-inventory-lock
        {itemId : Item ID}
        {warehouseId : Warehouse ID}
        {qty : Quantity to record inbound}
        {unitCost : Unit cost}
        {sourceModel : Fully-qualified class name of the polymorphic source model}
        {sourceId : ID of the source model}
        {date : Movement date (Y-m-d)}
        {sleepSeconds : Seconds to hold the lock after writing, before committing}';

    protected $hidden = true;

    protected $description = 'Test-support only: holds an InventoryService lock open for a controlled duration.';

    public function handle(InventoryService $inventory): int
    {
        if (! app()->environment('testing')) {
            $this->error('This command only runs in the testing environment.');

            return self::FAILURE;
        }

        $item = Item::findOrFail($this->argument('itemId'));
        $warehouse = Warehouse::findOrFail($this->argument('warehouseId'));
        $sourceModelClass = $this->argument('sourceModel');
        $source = $sourceModelClass::findOrFail($this->argument('sourceId'));

        DB::transaction(function () use ($inventory, $item, $warehouse, $source) {
            $inventory->recordInbound(
                $item,
                $warehouse,
                $this->argument('qty'),
                $this->argument('unitCost'),
                $source,
                $this->argument('date'),
            );

            // Signal to the parent test process that the write has happened
            // and the row lock is now held — flush immediately, don't rely
            // on PHP's default output buffering.
            fwrite(STDOUT, "LOCK_HELD\n");
            fflush(STDOUT);

            sleep((int) $this->argument('sleepSeconds'));
        });

        fwrite(STDOUT, "DONE\n");
        fflush(STDOUT);

        return self::SUCCESS;
    }
}
