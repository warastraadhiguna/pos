<?php

namespace App\Http\Controllers\Konsinyasi;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentPayment;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Services\CashAccountService;
use App\Services\ConsignmentPayableReportService;
use App\Services\ConsignmentPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Bayar Hutang Konsinyasi" -- struktur PERSIS SupplierPaymentController,
 * sengaja kelas terpisah (lihat ConsignmentPaymentService/rancangan).
 */
class ConsignmentPaymentController extends Controller
{
    public function __construct(
        private readonly ConsignmentPaymentService $payments,
        private readonly ConsignmentPayableReportService $payableReport,
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

        $payments = ConsignmentPayment::with(['supplier', 'allocations'])
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

        return Inertia::render('Konsinyasi/Payments/Index', [
            'payments' => $payments,
            'outstandingBySupplier' => $this->payableReport->outstandingBySupplier(),
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $initialSupplier = null;
        if ($request->filled('supplier_id')) {
            $initialSupplier = Supplier::find($request->integer('supplier_id'), ['id', 'name']);
        }

        return Inertia::render('Konsinyasi/Payments/Create', [
            'outlets' => Outlet::orderBy('name')->get(),
            'initialSupplier' => $initialSupplier,
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
            'allocations.*.consignment_accrual_id' => ['nullable', 'exists:consignment_accruals,id'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $this->payments->recordPayment($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal mencatat pembayaran: '.$e->getMessage());
        }

        return Redirect::route('konsinyasi.payments.index')->with('success', 'Pembayaran hutang konsinyasi berhasil dicatat.');
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
        ]);

        $supplierId = (int) $validated['supplier_id'];

        return response()->json([
            'outstanding' => $this->payableReport->outstandingForSupplier($supplierId),
            'accruals' => $this->payableReport->accrualBreakdownForSupplier($supplierId),
        ]);
    }

    public function fifoPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $accruals = $this->payableReport->accrualBreakdownForSupplier((int) $validated['supplier_id']);
        $unpaidAccruals = array_values(array_filter($accruals, fn (array $accrual) => $accrual['status'] !== 'lunas'));

        $allocations = $this->payments->allocateFifo(
            array_map(fn (array $accrual) => [
                'consignment_accrual_id' => $accrual['consignment_accrual_id'],
                'remaining' => $accrual['remaining'],
            ], $unpaidAccruals),
            $validated['amount'],
        );

        return response()->json(['allocations' => $allocations]);
    }
}
