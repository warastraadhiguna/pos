<?php

namespace App\Http\Controllers\Pembelian;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\CashAccountService;
use App\Services\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function create(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if ($purchaseOrder->status === 'received') {
            return Redirect::route('pembelian.purchase-orders.show', $purchaseOrder)
                ->with('error', 'PO ini sudah diterima penuh.');
        }

        $purchaseOrder->load(['supplier', 'lines.item.baseUom', 'lines.purchaseUom']);

        $lines = $purchaseOrder->lines->map(fn (PurchaseOrderLine $line) => [
            'id' => $line->id,
            'item_sku' => $line->item->sku,
            'item_name' => $line->item->name,
            'qty' => $line->qty,
            'purchase_uom_code' => $line->purchaseUom->code,
            'received_qty_base_uom' => $this->purchases->receivedQtyInBaseUom($line),
            'item_base_uom_code' => $line->item->baseUom->code,
            'remaining_qty_purchase_uom' => $this->purchases->remainingQtyInPurchaseUom($line),
        ]);

        return Inertia::render('Pembelian/GoodsReceipts/Create', [
            'purchaseOrder' => $purchaseOrder,
            'lines' => $lines,
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'payment_method' => ['required', 'in:cash,credit'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => [
                'required',
                Rule::exists('purchase_order_lines', 'id')->where('purchase_order_id', $purchaseOrder->id),
            ],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
            'confirm_overreceipt' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $receivedLines = collect($validated['lines'])
            ->filter(fn (array $line) => (float) $line['qty'] > 0)
            ->pluck('qty', 'purchase_order_line_id')
            ->all();

        if ($receivedLines === []) {
            return Redirect::back()->with('error', 'Isi minimal satu qty penerimaan.');
        }

        // Kelebihan terima tetap sah (supplier kadang kirim lebih), tapi
        // tidak boleh diproses diam-diam — wajib flag konfirmasi eksplisit.
        // Ini jaring pengaman backend; alur normal sudah menampilkan dialog
        // konfirmasi di UI sebelum flag ini ikut terkirim.
        $overs = $this->purchases->detectOverReceipts($purchaseOrder, $receivedLines);

        if ($overs !== [] && ! ($validated['confirm_overreceipt'] ?? false)) {
            $itemNames = collect($overs)->map(fn (array $over) => $over['line']->item->sku)->join(', ');

            return Redirect::back()
                ->withInput()
                ->with('error', "Qty diterima melebihi sisa pesanan untuk: {$itemNames}. Konfirmasi kelebihan penerimaan diperlukan.");
        }

        try {
            $this->purchases->receiveGoods($purchaseOrder, $receivedLines, $validated['date'], $validated['payment_method'], $validated['notes'] ?? null, $validated['cash_account_code'] ?? null);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal menerima barang: '.$e->getMessage());
        }

        return Redirect::route('pembelian.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Penerimaan barang berhasil dicatat.');
    }
}
