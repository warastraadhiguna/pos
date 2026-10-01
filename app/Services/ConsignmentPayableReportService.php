<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ConsignmentAccrual;
use App\Models\ConsignmentPayment;
use App\Models\ConsignmentPaymentAllocation;
use App\Models\JournalLine;
use App\Models\Supplier;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Outstanding Hutang Konsinyasi per supplier -- pola & disiplin PERSIS
 * SupplierPayableReportService (selalu diturunkan dari data, tidak pernah
 * dari kolom saldo cache), TAPI dibaca langsung dari `consignment_accruals`
 * (bukan `journal_lines`) -- lihat docblock ConsignmentAccrual/migrasinya
 * untuk alasan kenapa itu perlu ada sama sekali (journal_lines tidak punya
 * dimensi supplier, satu Sale bisa mencampur 2 supplier konsinyasi berbeda
 * di baris kredit akun 2-3000 yang sama).
 *
 * `totalOutstanding()` harus selalu rekonsiliasi persis dengan saldo akun
 * 2-3000 di FinancialReportService::balanceSheet() pada tanggal yang sama
 * (lihat test) -- `consignment_accruals.amount` dibangun dari jumlah PERSIS
 * yang diposting ke 2-3000 oleh SaleService, jadi kedua angka itu memang
 * selalu berasal dari kejadian yang sama, cuma dikelompokkan beda (per
 * supplier di sini, per akun di Neraca).
 */
class ConsignmentPayableReportService
{
    private const SCALE = 4;

    // Chart of accounts code -- diseed 2026_10_01_100800_seed_consignment_account_and_permission.php.
    private const ACCOUNT_HUTANG_KONSINYASI = '2-3000';

    /**
     * @return array<int, array{supplier_id: int, supplier_name: string, total_accrued: string, total_paid: string, outstanding: string}>
     */
    public function outstandingBySupplier(DateTimeInterface|string|null $asOfDate = null): array
    {
        $balances = $this->supplierBalances($asOfDate);

        return Supplier::orderBy('name')->get()
            ->map(fn (Supplier $supplier) => $this->rowFor($supplier, $balances))
            ->all();
    }

    public function outstandingForSupplier(int $supplierId, DateTimeInterface|string|null $asOfDate = null): string
    {
        $balances = $this->supplierBalances($asOfDate);
        $row = $balances->get($supplierId, ['total_accrued' => '0', 'total_paid' => '0']);

        return bcsub($row['total_accrued'], $row['total_paid'], self::SCALE);
    }

    /**
     * Total seluruh supplier — harus persis sama dengan saldo akun 2-3000
     * di Neraca pada tanggal yang sama.
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
     * Setiap accrual (satu per Sale×supplier yang menjual item konsinyasi
     * milik supplier ini) dengan status pelunasannya -- terurut tanggal
     * TERTUA DULU, siap pakai untuk alokasi FIFO.
     *
     * @return array<int, array{
     *     consignment_accrual_id: int, sale_id: int, date: string,
     *     accrual_total: string, allocated: string, remaining: string, status: string,
     * }>
     */
    public function accrualBreakdownForSupplier(int $supplierId, DateTimeInterface|string|null $asOfDate = null): array
    {
        $accruals = ConsignmentAccrual::query()
            ->where('supplier_id', $supplierId)
            ->whereNull('voided_at')
            ->when($asOfDate, fn ($query) => $query->where('date', '<=', $asOfDate))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return $accruals->map(fn (ConsignmentAccrual $accrual) => $this->accrualStatus($accrual))->all();
    }

    /**
     * @return array{
     *     consignment_accrual_id: int, sale_id: int, date: string,
     *     accrual_total: string, allocated: string, remaining: string, status: string,
     * }
     */
    public function accrualStatus(ConsignmentAccrual $accrual): array
    {
        $accrualTotal = bcadd((string) $accrual->amount, '0', self::SCALE);
        $allocated = bcadd(
            (string) ConsignmentPaymentAllocation::where('consignment_accrual_id', $accrual->id)->sum('amount'),
            '0',
            self::SCALE,
        );
        $remaining = bcsub($accrualTotal, $allocated, self::SCALE);

        $status = match (true) {
            bccomp($remaining, '0', self::SCALE) <= 0 => 'lunas',
            bccomp($allocated, '0', self::SCALE) > 0 => 'sebagian',
            default => 'belum',
        };

        return [
            'consignment_accrual_id' => $accrual->id,
            'sale_id' => $accrual->sale_id,
            'date' => (string) $accrual->date,
            'accrual_total' => $accrualTotal,
            'allocated' => $allocated,
            'remaining' => $remaining,
            'status' => $status,
        ];
    }

    /**
     * @param  Collection<int, array{total_accrued: string, total_paid: string}>  $balances
     * @return array{supplier_id: int, supplier_name: string, total_accrued: string, total_paid: string, outstanding: string}
     */
    private function rowFor(Supplier $supplier, Collection $balances): array
    {
        $row = $balances->get($supplier->id, ['total_accrued' => '0', 'total_paid' => '0']);

        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'total_accrued' => $row['total_accrued'],
            'total_paid' => $row['total_paid'],
            'outstanding' => bcsub($row['total_accrued'], $row['total_paid'], self::SCALE),
        ];
    }

    /**
     * @return Collection<int, array{total_accrued: string, total_paid: string}> keyed by supplier_id
     */
    private function supplierBalances(DateTimeInterface|string|null $asOfDate): Collection
    {
        $accrued = ConsignmentAccrual::query()
            ->whereNull('voided_at')
            ->when($asOfDate, fn ($query) => $query->where('date', '<=', $asOfDate))
            ->selectRaw('supplier_id, SUM(amount) as total')
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        // Dibaca dari journal_lines (bukan dijumlah dari allocations) --
        // ConsignmentPayment punya supplier_id LANGSUNG (beda dari
        // GoodsReceipt yang perlu lewat PurchaseOrder), jadi ini bisa pakai
        // pola SupplierPayableReportService::supplierBalances() persis:
        // seluruh jumlah Dr 2-3000 per pembayaran ikut terhitung di sini,
        // TERMASUK porsi uang muka (allocation ber-consignment_accrual_id
        // null) -- supaya totalOutstanding() selalu rekonsiliasi PERSIS
        // dengan saldo akun 2-3000 di Neraca, bukan cuma porsi yang
        // kebetulan sudah teralokasi ke accrual tertentu.
        $hutangKonsinyasiAccountId = Account::where('code', self::ACCOUNT_HUTANG_KONSINYASI)->firstOrFail()->id;

        $paid = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('consignment_payments', 'consignment_payments.id', '=', 'journals.source_id')
            ->where('journals.source_type', ConsignmentPayment::class)
            ->where('journal_lines.account_id', $hutangKonsinyasiAccountId)
            ->when($asOfDate, fn ($query) => $query->where('journals.date', '<=', $asOfDate))
            ->selectRaw('consignment_payments.supplier_id as supplier_id, SUM(journal_lines.debit) as total')
            ->groupBy('consignment_payments.supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $supplierIds = $accrued->keys()->merge($paid->keys())->unique();

        return $supplierIds->mapWithKeys(fn ($id) => [
            (int) $id => [
                'total_accrued' => (string) ($accrued->get($id)->total ?? '0'),
                'total_paid' => (string) ($paid->get($id)->total ?? '0'),
            ],
        ]);
    }
}
