<?php

namespace App\Http\Controllers\Pembelian;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\CashAccountService;
use App\Services\SupplierPayableReportService;
use App\Services\SupplierPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SupplierPaymentController extends Controller
{
    public function __construct(
        private readonly SupplierPaymentService $payments,
        private readonly SupplierPayableReportService $payableReport,
        private readonly CashAccountService $cashAccounts,
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

        $payments = SupplierPayment::with(['supplier', 'allocations'])
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('memo', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Pembelian/SupplierPayments/Index', [
            'payments' => $payments,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
        ]);
    }

    /**
     * supplier_id/goods_receipt_id query params are STARTING POINTS ONLY —
     * used by the "Bayar" quick-link on the PO page to pre-select a
     * supplier and pre-check one nota. The frontend reads these once at
     * mount; nothing re-syncs from them afterward, so switching to FIFO
     * mode or editing the checked notas never snaps back.
     */
    public function create(Request $request): Response
    {
        $initialSupplier = null;
        if ($request->filled('supplier_id')) {
            $initialSupplier = Supplier::find($request->integer('supplier_id'), ['id', 'name']);
        }

        return Inertia::render('Pembelian/SupplierPayments/Create', [
            'outlets' => Outlet::orderBy('name')->get(),
            'initialSupplier' => $initialSupplier,
            'initialGoodsReceiptId' => $request->filled('goods_receipt_id') ? $request->integer('goods_receipt_id') : null,
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'memo' => ['nullable', 'string', 'max:500'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.goods_receipt_id' => ['nullable', 'exists:goods_receipts,id'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $this->payments->recordPayment($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal mencatat pembayaran: '.$e->getMessage());
        }

        return Redirect::route('pembelian.supplier-payments.index')->with('success', 'Pembayaran hutang berhasil dicatat.');
    }

    /**
     * Sisa hutang (agregat) + rincian nota untuk satu supplier — dipanggil
     * dari form pembayaran begitu supplier dipilih. Sengaja memakai
     * SupplierPayableReportService yang SAMA dengan laporan hutang, bukan
     * rumus terpisah, supaya tidak ada dua angka yang bisa saling
     * menyimpang.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
        ]);

        $supplierId = (int) $validated['supplier_id'];

        return response()->json([
            'outstanding' => $this->payableReport->outstandingForSupplier($supplierId),
            'notas' => $this->payableReport->notaBreakdownForSupplier($supplierId),
        ]);
    }

    /**
     * FIFO otomatis dihitung di backend (SupplierPaymentService::allocateFifo(),
     * fungsi murni yang sama yang dipakai saat submit) supaya pratinjau di
     * form selalu identik dengan apa yang benar-benar akan tersimpan kalau
     * user tidak mengubah apa pun secara manual.
     */
    public function fifoPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $notas = $this->payableReport->notaBreakdownForSupplier((int) $validated['supplier_id']);
        $unpaidNotas = array_values(array_filter($notas, fn (array $nota) => $nota['status'] !== 'lunas'));

        $allocations = $this->payments->allocateFifo(
            array_map(fn (array $nota) => ['goods_receipt_id' => $nota['goods_receipt_id'], 'remaining' => $nota['remaining']], $unpaidNotas),
            $validated['amount'],
        );

        return response()->json(['allocations' => $allocations]);
    }
}
