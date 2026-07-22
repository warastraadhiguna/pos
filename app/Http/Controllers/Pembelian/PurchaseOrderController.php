<?php

namespace App\Http\Controllers\Pembelian;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SupplierPayableReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly SupplierPayableReportService $payableReport,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $dateFrom = $filters['date_from'] ?? now()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $search = $filters['search'] ?? '';

        $purchaseOrders = PurchaseOrder::with('supplier')
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->when($search !== '', function ($query) use ($search) {
                // "PO-12" atau "12" -> cocokkan nomor PO; sebagian nama
                // supplier juga dicocokkan supaya satu kolom cari bisa dipakai
                // untuk keduanya.
                $poNumber = preg_replace('/^po-?/i', '', trim($search));

                $query->where(function ($q) use ($search, $poNumber) {
                    if ($poNumber !== '' && is_numeric($poNumber)) {
                        $q->orWhere('id', 'like', "%{$poNumber}%");
                    }
                    $q->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id')
            ->get();

        // Satu badge per nota (GoodsReceipt), dikelompokkan per PO — satu PO
        // bisa punya beberapa penerimaan dengan status/metode berbeda-beda,
        // jadi badge tunggal per baris PO akan menyesatkan.
        $receiptBadgesByPo = GoodsReceipt::whereIn('purchase_order_id', $purchaseOrders->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('purchase_order_id')
            ->map(fn ($receipts) => $receipts->map(fn (GoodsReceipt $receipt) => $this->payableReport->notaStatus($receipt))->values());

        return Inertia::render('Pembelian/PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'receiptBadgesByPo' => $receiptBadgesByPo,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Pembelian/PurchaseOrders/Create', [
            'warehouses' => Warehouse::orderBy('name')->get(),
            // Supplier & item TIDAK dimuat penuh di sini — keduanya dipilih
            // lewat SupplierCombobox/ItemCombobox (search-as-you-type via
            // master.suppliers.search / master.items.search), bukan dropdown
            // yang memuat semuanya.
            'uoms' => Uom::orderBy('code')->get(),
            'taxRates' => TaxRate::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.purchase_uom_id' => ['required', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate_id' => ['nullable', 'exists:tax_rates,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $po = $this->purchases->createPurchaseOrder($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::route('pembelian.purchase-orders.create')->with('error', 'Gagal membuat PO: '.$e->getMessage());
        }

        return Redirect::route('pembelian.purchase-orders.show', $po)->with('success', 'PO berhasil dibuat.');
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load(['supplier', 'warehouse', 'lines.item.baseUom', 'lines.purchaseUom', 'lines.taxRate']);

        $lines = $purchaseOrder->lines->map(fn (PurchaseOrderLine $line) => [
            'id' => $line->id,
            'item' => $line->item,
            'qty' => $line->qty,
            'purchase_uom' => $line->purchaseUom,
            'unit_price' => $line->unit_price,
            'tax_rate' => $line->taxRate,
            'received_qty_base_uom' => $this->purchases->receivedQtyInBaseUom($line),
        ]);

        $receipts = GoodsReceipt::where('purchase_order_id', $purchaseOrder->id)
            ->with('lines.item')
            ->orderByDesc('id')
            ->get()
            ->map(fn (GoodsReceipt $receipt) => [
                ...$receipt->toArray(),
                'nota_status' => $this->payableReport->notaStatus($receipt),
            ]);

        return Inertia::render('Pembelian/PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
            'lines' => $lines,
            'receipts' => $receipts,
        ]);
    }
}
