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
 * MySQL row lock (via InventoryService::recordOutbound()) open for a
 * controlled duration — the OUTBOUND counterpart of
 * ConcurrencyTestHoldInventoryLock (which covers recordInbound()).
 *
 * This is the exact primitive Variasi Berbayar Tahap 2
 * (SaleService::consumeSaleLineVariations()) calls to consume a variation's
 * BOM component, PERSIS the same call product BOM consumption already makes
 * — proving recordOutbound()'s lock serializes two genuinely concurrent
 * callers is what proves Tahap 2's variation stock consumption is safe
 * under concurrency, without needing a second locking mechanism of its own.
 */
class ConcurrencyTestHoldInventoryOutbound extends Command
{
    protected $signature = 'concurrency-test:hold-inventory-outbound
        {itemId : Item ID}
        {warehouseId : Warehouse ID}
        {qty : Quantity to record outbound}
        {sourceModel : Fully-qualified class name of the polymorphic source model}
        {sourceId : ID of the source model}
        {date : Movement date (Y-m-d)}
        {sleepSeconds : Seconds to hold the lock after writing, before committing}';

    protected $hidden = true;

    protected $description = 'Test-support only: holds an InventoryService outbound lock open for a controlled duration.';

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
        $qty = $this->argument('qty');
        $sleepSeconds = (int) $this->argument('sleepSeconds');
        $date = $this->argument('date');

        DB::transaction(function () use ($inventory, $item, $warehouse, $source, $qty, $sleepSeconds, $date) {
            // recordOutbound() wraps its own DB::transaction() internally —
            // nesting inside this outer one is fine (Laravel savepoints),
            // same pattern SaleService::createSale() already relies on.
            // The sleep below happens OUTSIDE that inner call, still inside
            // THIS outer transaction/connection, so the row lock acquired by
            // recordOutbound()'s lockLedger() stays held for the full
            // duration — mirrors ConcurrencyTestHoldInventoryLock exactly.
            $inventory->recordOutbound($item, $warehouse, $qty, $source, $date);

            fwrite(STDOUT, "LOCK_HELD\n");
            fflush(STDOUT);

            sleep($sleepSeconds);
        });

        fwrite(STDOUT, "DONE\n");
        fflush(STDOUT);

        return self::SUCCESS;
    }
}
