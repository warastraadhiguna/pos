<?php

namespace App\Services;

use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Outstanding accounts payable per supplier. Deliberately reads straight
 * from journal_lines (never from goods_receipts.grand_total or any cached
 * balance) so the total always reconciles with the Hutang Usaha (2-1000)
 * balance on the Balance Sheet — same underlying ledger rows, just grouped
 * by supplier instead of by account. There is no cached "saldo hutang"
 * column anywhere (same discipline as stock: never a single cached qty,
 * always derived from movements).
 *
 * journals has no supplier_id column and never will for historical rows —
 * supplier is resolved per source_type via its own relation chain
 * (GoodsReceipt -> PurchaseOrder -> Supplier, or SupplierPayment ->
 * Supplier directly). This keeps zero existing journals/journal_lines rows
 * touched by this feature.
 */
class SupplierPayableReportService
{
    private const SCALE = 4;

    private const ACCOUNT_HUTANG_USAHA = '2-1000';

    /**
     * @return array<int, array{supplier_id: int, supplier_name: string, total_credit: string, total_paid: string, outstanding: string}>
     */
    public function outstandingBySupplier(DateTimeInterface|string|null $asOfDate = null): array
    {
        $balances = $this->supplierBalances($asOfDate);

        return Supplier::orderBy('name')->get()
            ->map(fn (Supplier $supplier) => $this->rowFor($supplier, $balances))
            ->all();
    }

    /**
     * Sisa hutang untuk SATU supplier. Dipakai juga oleh form pencatatan
     * pembayaran supaya angka yang ditampilkan di sana selalu berasal dari
     * sumber yang sama dengan laporan ini — tidak ada rumus kedua yang bisa
     * menyimpang.
     */
    public function outstandingForSupplier(int $supplierId, DateTimeInterface|string|null $asOfDate = null): string
    {
        $balances = $this->supplierBalances($asOfDate);
        $row = $balances->get($supplierId, ['total_credit' => '0', 'total_paid' => '0']);

        return bcsub($row['total_credit'], $row['total_paid'], self::SCALE);
    }

    /**
     * Total seluruh supplier — ini yang harus persis sama dengan saldo akun
     * 2-1000 di Neraca (FinancialReportService::balanceSheet()) pada
     * tanggal yang sama.
     */
    public function totalOutstanding(DateTimeInterface|string|null $asOfDate = null): string
    {
        return array_reduce(
            $this->outstandingBySupplier($asOfDate),
            fn ($carry, array $row) => bcadd($carry, $row['outstanding'], self::SCALE),
            '0',
        );
    }

    /**
     * Setiap nota kredit (GoodsReceipt bermetode 'credit') milik supplier
     * ini, dengan status pelunasannya — terurut tanggal TERTUA DULU (siap
     * pakai langsung untuk alokasi FIFO di SupplierPaymentService). Nota
     * tunai tidak pernah muncul di sini — tidak pernah ada hutang untuk
     * nota tunai sama sekali.
     *
     * @return array<int, array{
     *     goods_receipt_id: int, purchase_order_id: int, date: string,
     *     nota_total: string, allocated: string, remaining: string, status: string,
     * }>
     */
    public function notaBreakdownForSupplier(int $supplierId, DateTimeInterface|string|null $asOfDate = null): array
    {
        $hutangAccountId = Account::where('code', self::ACCOUNT_HUTANG_USAHA)->firstOrFail()->id;

        $receipts = GoodsReceipt::query()
            ->whereHas('purchaseOrder', fn ($query) => $query->where('supplier_id', $supplierId))
            ->where('payment_method', 'credit')
            ->when($asOfDate, fn ($query) => $query->where('date', '<=', $asOfDate))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return $receipts
            ->map(fn (GoodsReceipt $receipt) => $this->notaStatus($receipt, $hutangAccountId))
            ->all();
    }

    /**
     * Status satu nota (satu GoodsReceipt). Nota tunai selalu berstatus
     * 'tunai' — tidak pernah punya hutang untuk dihitung. $hutangAccountId
     * boleh dilewatkan oleh pemanggil beruntun (notaBreakdownForSupplier)
     * supaya tidak query akun berulang untuk tiap nota.
     *
     * @return array{
     *     goods_receipt_id: int, purchase_order_id: int, date: string,
     *     nota_total: string, allocated: string, remaining: string, status: string,
     * }
     */
    public function notaStatus(GoodsReceipt $receipt, ?int $hutangAccountId = null): array
    {
        if ($receipt->payment_method !== 'credit') {
            return [
                'goods_receipt_id' => $receipt->id,
                'purchase_order_id' => $receipt->purchase_order_id,
                'date' => (string) $receipt->date,
                'nota_total' => '0.0000',
                'allocated' => '0.0000',
                'remaining' => '0.0000',
                'status' => 'tunai',
            ];
        }

        $hutangAccountId ??= Account::where('code', self::ACCOUNT_HUTANG_USAHA)->firstOrFail()->id;

        // bcadd(...,'0',SCALE) normalizes SUM()'s output: MySQL returns a
        // proper decimal string when rows match, but Laravel's query builder
        // falls back to plain int 0 when none do — this keeps the shape
        // consistent (always a scale-4 string) either way.
        $notaTotal = bcadd((string) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.source_type', GoodsReceipt::class)
            ->where('journals.source_id', $receipt->id)
            ->where('journal_lines.account_id', $hutangAccountId)
            ->sum('journal_lines.credit'), '0', self::SCALE);

        $allocated = bcadd((string) SupplierPaymentAllocation::where('goods_receipt_id', $receipt->id)->sum('amount'), '0', self::SCALE);

        $remaining = bcsub($notaTotal, $allocated, self::SCALE);

        $status = match (true) {
            bccomp($remaining, '0', self::SCALE) <= 0 => 'lunas',
            bccomp($allocated, '0', self::SCALE) > 0 => 'sebagian',
            default => 'belum',
        };

        return [
            'goods_receipt_id' => $receipt->id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'date' => (string) $receipt->date,
            'nota_total' => $notaTotal,
            'allocated' => $allocated,
            'remaining' => $remaining,
            'status' => $status,
        ];
    }

    /**
     * @param  Collection<int, array{total_credit: string, total_paid: string}>  $balances
     * @return array{supplier_id: int, supplier_name: string, total_credit: string, total_paid: string, outstanding: string}
     */
    private function rowFor(Supplier $supplier, Collection $balances): array
    {
        $row = $balances->get($supplier->id, ['total_credit' => '0', 'total_paid' => '0']);

        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'total_credit' => $row['total_credit'],
            'total_paid' => $row['total_paid'],
            'outstanding' => bcsub($row['total_credit'], $row['total_paid'], self::SCALE),
        ];
    }

    /**
     * @return Collection<int, array{total_credit: string, total_paid: string}> keyed by supplier_id
     */
    private function supplierBalances(DateTimeInterface|string|null $asOfDate): Collection
    {
        $hutangAccountId = Account::where('code', self::ACCOUNT_HUTANG_USAHA)->firstOrFail()->id;

        // Penambahan hutang: baris kredit ke 2-1000 yang bersumber dari
        // GoodsReceipt berbasis kredit. Penerimaan bermetode tunai TIDAK
        // PERNAH menyentuh 2-1000 sama sekali (lihat
        // PurchaseService::postGoodsReceiptJournal()), jadi otomatis tidak
        // ikut ter-hitung di sini tanpa perlu filter payment_method eksplisit.
        $credits = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'journals.source_id')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'goods_receipts.purchase_order_id')
            ->where('journals.source_type', GoodsReceipt::class)
            ->where('journal_lines.account_id', $hutangAccountId)
            ->when($asOfDate, fn ($query) => $query->where('journals.date', '<=', $asOfDate))
            ->selectRaw('purchase_orders.supplier_id as supplier_id, SUM(journal_lines.credit) as total')
            ->groupBy('purchase_orders.supplier_id')
            ->get()
            ->keyBy('supplier_id');

        // Pengurangan hutang: baris debit ke 2-1000 dari SupplierPayment.
        $paid = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('supplier_payments', 'supplier_payments.id', '=', 'journals.source_id')
            ->where('journals.source_type', SupplierPayment::class)
            ->where('journal_lines.account_id', $hutangAccountId)
            ->when($asOfDate, fn ($query) => $query->where('journals.date', '<=', $asOfDate))
            ->selectRaw('supplier_payments.supplier_id as supplier_id, SUM(journal_lines.debit) as total')
            ->groupBy('supplier_payments.supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $supplierIds = $credits->keys()->merge($paid->keys())->unique();

        return $supplierIds->mapWithKeys(fn ($id) => [
            (int) $id => [
                'total_credit' => (string) ($credits->get($id)->total ?? '0'),
                'total_paid' => (string) ($paid->get($id)->total ?? '0'),
            ],
        ]);
    }
}
