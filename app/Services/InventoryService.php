<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Models\UomConversion;
use App\Models\Warehouse;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    private const SCALE = 4;

    /**
     * Record incoming stock and recompute the Moving Average cost:
     * new_avg = (running_qty * running_avg + qty_in * unit_cost) / (running_qty + qty_in).
     */
    public function recordInbound(
        Item $item,
        Warehouse $warehouse,
        int|float|string $qty,
        int|float|string $unitCost,
        Model $source,
        DateTimeInterface|string $date,
    ): StockMovement {
        return DB::transaction(function () use ($item, $warehouse, $qty, $unitCost, $source, $date) {
            $qty = (string) $qty;
            $unitCost = (string) $unitCost;

            $last = $this->lockLedger($item, $warehouse);
            $runningQty = $last?->running_qty ?? '0';
            $runningAvg = $last?->running_average_cost ?? '0';

            $newRunningQty = bcadd($runningQty, $qty, self::SCALE);
            $newRunningAvg = $this->weightedAverage($runningQty, $runningAvg, $qty, $unitCost, $newRunningQty);

            $movement = new StockMovement([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'date' => $date,
                'qty_in' => $qty,
                'qty_out' => '0',
                'unit_cost' => $unitCost,
                'running_qty' => $newRunningQty,
                'running_average_cost' => $newRunningAvg,
            ]);
            $movement->source()->associate($source);
            $movement->save();

            return $movement;
        });
    }

    /**
     * Record outgoing stock. The average cost is untouched by an outbound
     * movement — only qty moves. Returns the total HPP (COGS) for the qty
     * pulled out, valued at the current running average cost.
     *
     * cost_only items (e.g. water) are never tracked in stock_movements —
     * their HPP is simply qty * standard_cost.
     */
    public function recordOutbound(
        Item $item,
        Warehouse $warehouse,
        int|float|string $qty,
        Model $source,
        DateTimeInterface|string $date,
    ): string {
        $qty = (string) $qty;

        if ($item->costing_type === 'cost_only') {
            return bcmul($qty, (string) $item->standard_cost, self::SCALE);
        }

        return DB::transaction(function () use ($item, $warehouse, $qty, $source, $date) {
            $last = $this->lockLedger($item, $warehouse);
            $runningQty = $last?->running_qty ?? '0';
            $runningAvg = $last?->running_average_cost ?? '0';

            $hpp = bcmul($qty, $runningAvg, self::SCALE);
            $newRunningQty = bcsub($runningQty, $qty, self::SCALE);

            $movement = new StockMovement([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'date' => $date,
                'qty_in' => '0',
                'qty_out' => $qty,
                'unit_cost' => $runningAvg,
                'running_qty' => $newRunningQty,
                'running_average_cost' => $runningAvg,
            ]);
            $movement->source()->associate($source);
            $movement->save();

            return $hpp;
        });
    }

    public function currentStock(Item $item, Warehouse $warehouse): string
    {
        return $this->lastMovementQuery($item, $warehouse)->first()?->running_qty ?? '0.0000';
    }

    /**
     * Lock the item+warehouse ledger and return qty + average cost read
     * FRESH from inside that lock — for callers that must serialize a
     * read-then-decide-then-write sequence against concurrent stock
     * movements (e.g. StockOpnameService::postOpname(), which computes a
     * diff from current stock and only afterwards calls
     * recordInbound()/recordOutbound()). A plain currentStock() call is not
     * enough for that pattern: it doesn't hold the lock past the read, so a
     * concurrent write could land in the gap between the read and the
     * eventual recordInbound()/recordOutbound() call, making the computed
     * diff stale by the time it's actually applied.
     *
     * Bundling both values in one lock acquisition (rather than the caller
     * locking once and then making two separate reads) makes the "these are
     * protected by the same lock" relationship explicit at the call site,
     * instead of relying on the reader to know the lock persists across
     * statements within the transaction.
     *
     * @return array{qty: string, average_cost: string}
     */
    public function lockAndReadCurrentStock(Item $item, Warehouse $warehouse): array
    {
        $last = $this->lockLedger($item, $warehouse);

        return [
            'qty' => $last?->running_qty ?? '0.0000',
            'average_cost' => $last?->running_average_cost ?? '0.0000',
        ];
    }

    public function currentAverageCost(Item $item, Warehouse $warehouse): string
    {
        return $this->lastMovementQuery($item, $warehouse)->first()?->running_average_cost ?? '0.0000';
    }

    private function weightedAverage(string $runningQty, string $runningAvg, string $qtyIn, string $unitCost, string $newRunningQty): string
    {
        if (bccomp($newRunningQty, '0', self::SCALE) === 0) {
            return '0.0000';
        }

        $existingValue = bcmul($runningQty, $runningAvg, self::SCALE);
        $incomingValue = bcmul($qtyIn, $unitCost, self::SCALE);

        return bcdiv(bcadd($existingValue, $incomingValue, self::SCALE), $newRunningQty, self::SCALE);
    }

    /**
     * Lock the item+warehouse ledger for the duration of the transaction and
     * return the last movement (or null if none exists yet).
     *
     * Locking only the last movement row isn't enough to prevent a race on
     * the very first movement ever recorded for an item+warehouse pair
     * (there is no row yet to lock), so we first serialize on the item row —
     * which always exists — before reading the ledger.
     */
    private function lockLedger(Item $item, Warehouse $warehouse): ?StockMovement
    {
        Item::query()->whereKey($item->id)->lockForUpdate()->first();

        return $this->lastMovementQuery($item, $warehouse)->lockForUpdate()->first();
    }

    private function lastMovementQuery(Item $item, Warehouse $warehouse)
    {
        return StockMovement::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderByDesc('id');
    }

    /**
     * Convert a BOM component qty from its recipe UOM to the item's base
     * UOM (the UOM stock_movements are tracked in), using uom_conversions.
     * Public (moved here from SaleService, which now delegates) — the same
     * conversion is also needed by producibleQtyForProducts() below, and
     * this is the natural single home for "stock unit" arithmetic.
     */
    public function convertToItemBaseUom(Item $item, Uom $fromUom, string $qty): string
    {
        if ($fromUom->id === $item->base_uom_id) {
            return $qty;
        }

        $direct = UomConversion::query()
            ->where('from_uom_id', $fromUom->id)
            ->where('to_uom_id', $item->base_uom_id)
            ->first();

        if ($direct) {
            return bcmul($qty, (string) $direct->factor, self::SCALE);
        }

        $inverse = UomConversion::query()
            ->where('from_uom_id', $item->base_uom_id)
            ->where('to_uom_id', $fromUom->id)
            ->first();

        if ($inverse) {
            return bcdiv($qty, (string) $inverse->factor, self::SCALE);
        }

        throw new RuntimeException(
            "No UOM conversion path from [{$fromUom->code}] to item [{$item->sku}]'s base UOM."
        );
    }

    /**
     * Current stock for every non-cost_only item in one query — same
     * N+1-avoidance technique as Api\ItemController::stock() (subquery for
     * the latest movement id per item, joined back), reused here so
     * producibleQtyForProducts() below doesn't call currentStock() in a
     * loop.
     *
     * @return array<int, string> item_id => running_qty
     */
    public function batchCurrentStock(Warehouse $warehouse): array
    {
        $latestMovementIds = StockMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->selectRaw('MAX(id) as id')
            ->groupBy('item_id');

        return StockMovement::query()
            ->joinSub($latestMovementIds, 'latest', 'stock_movements.id', '=', 'latest.id')
            ->get(['stock_movements.item_id', 'stock_movements.running_qty'])
            ->pluck('running_qty', 'item_id')
            ->all();
    }

    /**
     * How many of each Product could be made/sold right now, from CURRENT
     * stock alone — min over the product's BOM components of
     * floor(component_current_stock / qty_needed_in_base_uom).
     *
     * Advisory only (same spirit as Api\ItemController::stock()): the real
     * stock deduction always happens server-side at sale time; this is
     * purely a "how much can I show on the kasir button right now" number,
     * safe to compute cheaply and refresh periodically.
     *
     * cost_only components (e.g. water — standard_cost only, never written
     * to stock_movements) are SKIPPED, not treated as "0 stock" — their
     * supply is never the bottleneck. A product is considered NOT
     * stock-constrained (result: null, meaning "don't show a number") when
     * it has zero components, or every component is cost_only — a
     * currentStock() of '0.0000' for a cost_only item (no ledger rows
     * exist at all) would otherwise wrongly zero out the whole product.
     *
     * Negative running stock (allowed elsewhere in this system, see
     * InventoryService docblocks/ROADMAP) is clamped to 0 here — a
     * negative "producible qty" would be a nonsensical number to show a
     * cashier.
     *
     * @param  Collection<int, Product>  $products  MUST have `components.item` and `components.uom` eager-loaded.
     * @return array<int, int|null> product_id => producible qty, or null if not stock-constrained.
     */
    public function producibleQtyForProducts(Collection $products, Warehouse $warehouse): array
    {
        $stockByItem = $this->batchCurrentStock($warehouse);
        $result = [];

        foreach ($products as $product) {
            $min = null;

            foreach ($product->components as $component) {
                $item = $component->item;
                if ($item->costing_type === 'cost_only') {
                    continue;
                }

                $neededPerUnit = $this->convertToItemBaseUom($item, $component->uom, (string) $component->qty);
                if (bccomp($neededPerUnit, '0', self::SCALE) === 0) {
                    continue;
                }

                $stock = $stockByItem[$item->id] ?? '0.0000';
                $producibleForComponent = max(0, (int) bcdiv($stock, $neededPerUnit, 0));

                $min = $min === null ? $producibleForComponent : min($min, $producibleForComponent);
            }

            $result[$product->id] = $min;
        }

        return $result;
    }
}
