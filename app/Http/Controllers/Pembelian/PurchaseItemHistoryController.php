<?php

namespace App\Http\Controllers\Pembelian;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptLine;
use App\Models\ItemCategory;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riwayat Pembelian per Item -- satu baris per goods_receipt_line.
 *
 * Sumbernya NOTA PENERIMAAN (GoodsReceipt), bukan baris PO: penerimaan
 * adalah satu-satunya titik stok & jurnal benar-benar bergerak (lihat
 * PurchaseService::receiveGoods()), dan metode bayar (tunai/kredit)
 * tercatat per nota, bukan per PO. PO yang belum diterima belum menjadi
 * pembelian.
 *
 * Qty & harga ditampilkan dalam SATUAN DASAR item (apa adanya dari
 * goods_receipt_lines) supaya rekap per item bisa dijumlahkan walau tiap
 * PO memesan dengan satuan beli berbeda. Nilai = qty × unit_cost, sebelum
 * PPN masukan.
 *
 * Pembelian tidak mencatat user pembuatnya, jadi padanan filter "Kasir"
 * di Riwayat Penjualan di sini adalah filter Supplier.
 */
class PurchaseItemHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'string', 'in:cash,credit'],
            'item_category_id' => ['nullable', 'integer'],
        ]);

        // Pembelian jauh lebih jarang dari penjualan -- default 30 hari
        // terakhir supaya halaman tidak kosong di hari tanpa penerimaan.
        $dateFrom = $filters['date_from'] ?? now()->subDays(29)->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $search = $filters['search'] ?? '';
        $supplierId = $filters['supplier_id'] ?? null;
        $paymentMethod = $filters['payment_method'] ?? null;
        $categoryId = $filters['item_category_id'] ?? null;

        $lines = GoodsReceiptLine::query()
            ->whereHas('goodsReceipt', function ($q) use ($dateFrom, $dateTo, $supplierId, $paymentMethod) {
                $q->whereDate('date', '>=', $dateFrom)
                    ->whereDate('date', '<=', $dateTo)
                    ->when($paymentMethod, fn ($sq) => $sq->where('payment_method', $paymentMethod))
                    ->when($supplierId, fn ($sq) => $sq->whereHas('purchaseOrder', fn ($pq) => $pq->where('supplier_id', $supplierId)));
            })
            ->when($categoryId, fn ($q) => $q->whereHas('item', fn ($iq) => $iq->where('item_category_id', $categoryId)))
            ->when($search !== '', function ($q) use ($search) {
                // "PO-12" atau "12" -> nomor PO (sama dengan Riwayat PO);
                // selain itu nama / SKU item.
                $poNumber = preg_replace('/^po-?/i', '', trim($search));

                $q->where(function ($sq) use ($search, $poNumber) {
                    if ($poNumber !== '' && is_numeric($poNumber)) {
                        $sq->orWhereHas('goodsReceipt', fn ($gq) => $gq->where('purchase_order_id', 'like', "%{$poNumber}%"));
                    }
                    $sq->orWhereHas('item', fn ($iq) => $iq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
                });
            })
            ->with([
                'goodsReceipt:id,purchase_order_id,date,payment_method',
                'goodsReceipt.purchaseOrder:id,supplier_id',
                'goodsReceipt.purchaseOrder.supplier:id,name',
                'item:id,sku,name,base_uom_id,item_category_id',
                'item.baseUom:id,code',
                'item.itemCategory:id,name',
            ])
            ->get()
            ->sortByDesc(fn (GoodsReceiptLine $line) => [$line->goodsReceipt->date->toDateString(), $line->goods_receipt_id])
            ->values();

        $rows = $lines->map(fn (GoodsReceiptLine $line) => [
            'id' => $line->id,
            'goods_receipt_id' => $line->goods_receipt_id,
            'purchase_order_id' => $line->goodsReceipt->purchase_order_id,
            // toDateString(): Carbon ter-serialisasi ke ISO UTC, yang
            // untuk tanggal tengah malam WIB jatuh ke hari SEBELUMNYA.
            'date' => $line->goodsReceipt->date->toDateString(),
            'supplier' => $line->goodsReceipt->purchaseOrder?->supplier?->name,
            'payment_method' => $line->goodsReceipt->payment_method,
            'item_id' => $line->item_id,
            'sku' => $line->item->sku,
            'item_name' => $line->item->name,
            'category' => $line->item->itemCategory?->name,
            'uom' => $line->item->baseUom?->code,
            'qty' => $line->qty,
            'unit_cost' => $line->unit_cost,
            'line_total' => bcmul((string) $line->qty, (string) $line->unit_cost, 4),
        ]);

        // bcmath, bukan Collection::sum() -- lihat SaleHistoryController.
        $recap = $rows
            ->groupBy('item_id')
            ->map(function ($group) {
                $qty = $group->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['qty'], 4), '0');
                $total = $group->reduce(fn (string $carry, array $row) => bcadd($carry, $row['line_total'], 4), '0');

                return [
                    'sku' => $group->first()['sku'],
                    'item_name' => $group->first()['item_name'],
                    'category' => $group->first()['category'],
                    'uom' => $group->first()['uom'],
                    'qty' => $qty,
                    'total' => $total,
                    'average_cost' => bccomp($qty, '0', 4) === 1 ? bcdiv($total, $qty, 4) : '0',
                    'receipt_count' => $group->pluck('goods_receipt_id')->unique()->count(),
                ];
            })
            ->sortByDesc(fn (array $row) => (float) $row['total'])
            ->values();

        return Inertia::render('Pembelian/PerItem', [
            'rows' => $rows,
            'recap' => $recap,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
                'supplier_id' => $supplierId,
                'payment_method' => $paymentMethod,
                'item_category_id' => $categoryId,
            ],
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'categories' => ItemCategory::orderBy('name')->get(['id', 'name']),
            'summary' => [
                'line_count' => $rows->count(),
                'total' => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['line_total'], 4), '0'),
                'by_method' => $rows
                    ->groupBy('payment_method')
                    ->map(fn ($group, string $method) => [
                        'payment_method' => $method,
                        'count' => $group->pluck('goods_receipt_id')->unique()->count(),
                        'total' => $group->reduce(fn (string $carry, array $row) => bcadd($carry, $row['line_total'], 4), '0'),
                    ])
                    ->sortKeys()
                    ->values(),
            ],
        ]);
    }
}
