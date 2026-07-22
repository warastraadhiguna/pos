<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\UomConversion;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PurchaseService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by Database\Seeders\FoundationSeeder.
    private const ACCOUNT_PERSEDIAAN = '1-1200';

    private const ACCOUNT_PPN_MASUKAN = '1-1300';

    private const ACCOUNT_HUTANG_USAHA = '2-1000';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Record a purchase order. This only captures INTENT to buy — it never
     * touches stock or the ledger. Those only happen on receiveGoods().
     *
     * @param  array{
     *     supplier_id: int,
     *     warehouse_id: int,
     *     date: DateTimeInterface|string,
     *     lines: array<int, array{item_id: int, qty: int|float|string, purchase_uom_id: int, unit_price: int|float|string, tax_rate_id?: ?int}>,
     *     notes?: ?string,
     * }  $data
     */
    public function createPurchaseOrder(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $po = new PurchaseOrder([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'date' => $data['date'],
                'status' => 'open',
                'subtotal' => '0',
                'tax_total' => '0',
                'grand_total' => '0',
                'notes' => $data['notes'] ?? null,
            ]);
            $po->save();

            $subtotal = '0';
            $taxTotal = '0';

            foreach ($data['lines'] as $lineData) {
                $qty = (string) $lineData['qty'];
                $unitPrice = (string) $lineData['unit_price'];
                $lineTotal = bcmul($qty, $unitPrice, self::SCALE);

                $taxRateId = $lineData['tax_rate_id'] ?? null;
                $lineTax = $taxRateId
                    ? bcmul($lineTotal, (string) TaxRate::findOrFail($taxRateId)->rate, self::SCALE)
                    : '0';

                $po->lines()->create([
                    'item_id' => $lineData['item_id'],
                    'qty' => $qty,
                    'purchase_uom_id' => $lineData['purchase_uom_id'],
                    'unit_price' => $unitPrice,
                    'tax_rate_id' => $taxRateId,
                ]);

                $subtotal = bcadd($subtotal, $lineTotal, self::SCALE);
                $taxTotal = bcadd($taxTotal, $lineTax, self::SCALE);
            }

            $po->update([
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'grand_total' => bcadd($subtotal, $taxTotal, self::SCALE),
            ]);

            return $po->fresh('lines');
        });
    }

    /**
     * Receive goods against a PO — this is the only place stock and the
     * ledger actually move. A PO may be received in several partial calls.
     *
     * @param  array<int, int|float|string>  $receivedLines  [purchase_order_line_id => qty_received] in the line's purchase_uom.
     * @param  string  $paymentMethod  'cash' or 'credit' — whether this receipt was paid on the spot
     *   (credits Kas) or owed to the supplier (credits Hutang Usaha). Applies to the WHOLE receipt,
     *   not per line — one physical delivery is either paid for or it isn't.
     * @param  ?string  $notes  Purely descriptive metadata (e.g. "barang sedikit penyok") — never
     *   read by any accounting/stock logic, so unlike $paymentMethod it's fine to default to null.
     * @param  ?string  $cashAccountCode  Which Kas/Bank account this receipt was paid FROM (see
     *   CashAccountService) — only meaningful/validated when $paymentMethod is 'cash'; ignored
     *   entirely for 'credit' (that path never touches Kas/Bank at all). Defaults to Kas when
     *   $paymentMethod is 'cash' and this is null.
     */
    public function receiveGoods(PurchaseOrder $po, array $receivedLines, DateTimeInterface|string $date, string $paymentMethod, ?string $notes = null, ?string $cashAccountCode = null): GoodsReceipt
    {
        if ($paymentMethod === 'cash') {
            $cashAccountCode ??= CashAccountService::DEFAULT_CODE;
            $this->cashAccounts->assertValidCashAccount($cashAccountCode);
        }

        return DB::transaction(function () use ($po, $receivedLines, $date, $paymentMethod, $notes, $cashAccountCode) {
            $warehouse = $po->warehouse;

            $receipt = new GoodsReceipt([
                'purchase_order_id' => $po->id,
                'warehouse_id' => $po->warehouse_id,
                'date' => $date,
                'payment_method' => $paymentMethod,
                'cash_account_code' => $cashAccountCode ?? CashAccountService::DEFAULT_CODE,
                'notes' => $notes,
            ]);
            $receipt->save();

            $goodsValue = '0'; // before tax, valued at PO purchase price
            $taxTotal = '0';

            foreach ($receivedLines as $purchaseOrderLineId => $qtyReceived) {
                // Scoped to $po->id so a caller can't slip in a line ID that
                // belongs to a different purchase order.
                $poLine = PurchaseOrderLine::with(['item', 'purchaseUom', 'taxRate'])
                    ->where('purchase_order_id', $po->id)
                    ->findOrFail($purchaseOrderLineId);
                $qtyReceived = (string) $qtyReceived;
                $item = $poLine->item;

                $factor = $this->resolveUomFactor($item, $poLine->purchaseUom);
                $qtyInBaseUom = bcmul($qtyReceived, $factor, self::SCALE);
                $unitCostInBaseUom = bcdiv((string) $poLine->unit_price, $factor, self::SCALE);

                $receipt->lines()->create([
                    'purchase_order_line_id' => $poLine->id,
                    'item_id' => $item->id,
                    'qty' => $qtyInBaseUom,
                    'unit_cost' => $unitCostInBaseUom,
                ]);

                $this->inventory->recordInbound($item, $warehouse, $qtyInBaseUom, $unitCostInBaseUom, $receipt, $date);

                $lineValue = bcmul($qtyReceived, (string) $poLine->unit_price, self::SCALE);
                $goodsValue = bcadd($goodsValue, $lineValue, self::SCALE);

                if ($poLine->tax_rate_id) {
                    $lineTax = bcmul($lineValue, (string) $poLine->taxRate->rate, self::SCALE);
                    $taxTotal = bcadd($taxTotal, $lineTax, self::SCALE);
                }
            }

            $this->postGoodsReceiptJournal($receipt, $goodsValue, $taxTotal, $date, $paymentMethod, $cashAccountCode ?? CashAccountService::DEFAULT_CODE);
            $this->updatePurchaseOrderStatus($po);

            return $receipt->fresh('lines');
        });
    }

    private function postGoodsReceiptJournal(GoodsReceipt $receipt, string $goodsValue, string $taxTotal, DateTimeInterface|string $date, string $paymentMethod, string $cashAccountCode): void
    {
        $grandTotal = bcadd($goodsValue, $taxTotal, self::SCALE);
        $lines = [];

        if (bccomp($goodsValue, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => self::ACCOUNT_PERSEDIAAN, 'debit' => $goodsValue, 'credit' => 0];
        }

        if (bccomp($taxTotal, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => self::ACCOUNT_PPN_MASUKAN, 'debit' => $taxTotal, 'credit' => 0];
        }

        // Tunai -> Kas/Bank pilihan berkurang di tempat (lihat
        // CashAccountService). Kredit -> berhutang ke supplier (perilaku
        // lama). Cuma sisi kredit yang bercabang; Persediaan & PPN Masukan
        // di atas sama sekali tidak berubah oleh pilihan ini.
        $creditAccount = match ($paymentMethod) {
            'cash' => $cashAccountCode,
            'credit' => self::ACCOUNT_HUTANG_USAHA,
            default => throw new InvalidArgumentException("Unknown payment method [{$paymentMethod}]."),
        };

        if (bccomp($grandTotal, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => $creditAccount, 'debit' => 0, 'credit' => $grandTotal];
        }

        if ($lines === []) {
            return;
        }

        $this->posting->post(
            lines: $lines,
            date: $date,
            source: $receipt,
            memo: "Penerimaan barang PO #{$receipt->purchase_order_id}",
        );
    }

    /**
     * Total qty received so far against a single PO line, in the item's
     * base_uom (i.e. summed straight from goods_receipt_lines.qty).
     */
    public function receivedQtyInBaseUom(PurchaseOrderLine $line): string
    {
        return GoodsReceiptLine::query()
            ->where('purchase_order_line_id', $line->id)
            ->get()
            ->reduce(fn ($carry, GoodsReceiptLine $grLine) => bcadd($carry, $grLine->qty, self::SCALE), '0');
    }

    /**
     * Ordered qty minus qty received so far, both expressed in the line's
     * purchase_uom — directly comparable to what a user types when
     * receiving goods (unlike receivedQtyInBaseUom(), which is in the
     * item's base_uom). Can be negative if the line was already
     * over-received in a prior receipt.
     */
    public function remainingQtyInPurchaseUom(PurchaseOrderLine $line): string
    {
        $line->loadMissing('item', 'purchaseUom');
        $factor = $this->resolveUomFactor($line->item, $line->purchaseUom);
        $receivedInPurchaseUom = bcdiv($this->receivedQtyInBaseUom($line), $factor, self::SCALE);

        return bcsub((string) $line->qty, $receivedInPurchaseUom, self::SCALE);
    }

    /**
     * Find lines in $receivedLines whose qty exceeds what's still owed on
     * the PO. Over-receiving is legal (suppliers sometimes ship more than
     * ordered) but must never happen silently — callers should surface
     * these to the user for explicit confirmation before calling
     * receiveGoods().
     *
     * @param  array<int, int|float|string>  $receivedLines  [purchase_order_line_id => qty] in purchase_uom.
     * @return array<int, array{line: PurchaseOrderLine, qty: string, remaining: string, ordered: string, extreme: bool}>
     */
    public function detectOverReceipts(PurchaseOrder $po, array $receivedLines): array
    {
        $overs = [];

        foreach ($receivedLines as $purchaseOrderLineId => $qty) {
            $poLine = PurchaseOrderLine::with(['item', 'purchaseUom'])
                ->where('purchase_order_id', $po->id)
                ->findOrFail($purchaseOrderLineId);

            $qty = (string) $qty;
            $remaining = $this->remainingQtyInPurchaseUom($poLine);

            if (bccomp($qty, $remaining, self::SCALE) > 0) {
                $overs[] = [
                    'line' => $poLine,
                    'qty' => $qty,
                    'remaining' => $remaining,
                    'ordered' => (string) $poLine->qty,
                    'extreme' => bccomp($qty, bcmul((string) $poLine->qty, '2', self::SCALE), self::SCALE) > 0,
                ];
            }
        }

        return $overs;
    }

    /**
     * A PO line's ordered/received qty can't be compared directly: ordered
     * qty is stored in purchase_uom, received qty is stored in the item's
     * base_uom. Received qty is summed per purchase_order_line_id, so two
     * lines ordering the same item are tracked independently.
     */
    private function updatePurchaseOrderStatus(PurchaseOrder $po): void
    {
        $po->loadMissing('lines.item', 'lines.purchaseUom');

        $fullyReceived = true;

        foreach ($po->lines as $line) {
            $factor = $this->resolveUomFactor($line->item, $line->purchaseUom);
            $orderedQtyInBaseUom = bcmul((string) $line->qty, $factor, self::SCALE);
            $receivedQtyInBaseUom = $this->receivedQtyInBaseUom($line);

            if (bccomp($receivedQtyInBaseUom, $orderedQtyInBaseUom, self::SCALE) < 0) {
                $fullyReceived = false;
            }
        }

        $po->update(['status' => $fullyReceived ? 'received' : 'partial']);
    }

    /**
     * Resolve the multiplier to go from $fromUom to $item's base_uom
     * (base_qty = from_qty * factor), via uom_conversions — same match
     * order as SaleService: same UOM, direct row, then inverse row.
     */
    private function resolveUomFactor(Item $item, Uom $fromUom): string
    {
        if ($fromUom->id === $item->base_uom_id) {
            return '1';
        }

        $direct = UomConversion::query()
            ->where('from_uom_id', $fromUom->id)
            ->where('to_uom_id', $item->base_uom_id)
            ->first();

        if ($direct) {
            return (string) $direct->factor;
        }

        $inverse = UomConversion::query()
            ->where('from_uom_id', $item->base_uom_id)
            ->where('to_uom_id', $fromUom->id)
            ->first();

        if ($inverse) {
            return bcdiv('1', (string) $inverse->factor, self::SCALE);
        }

        throw new RuntimeException(
            "No UOM conversion path from [{$fromUom->code}] to item [{$item->sku}]'s base UOM."
        );
    }
}
