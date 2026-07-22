<?php

namespace App\Services;

use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\JournalLine;
use App\Models\Sale;
use DateTimeInterface;
use Illuminate\Support\Collection;

class TaxReportService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by Database\Seeders\FoundationSeeder.
    private const ACCOUNT_PPN_KELUARAN = '2-1100';

    private const ACCOUNT_PPN_MASUKAN = '1-1300';

    /**
     * PPN report for a date range — output tax, input tax, and the amount
     * payable (or overpaid, if negative) to the tax office.
     *
     * Both totals are read directly from journal_lines/journals (the
     * general ledger), never recomputed from sales/purchases — same
     * discipline as FinancialReportService, so this report can never
     * drift from what the books actually say. Output tax is the credit
     * movement on PPN Keluaran (one line per sale journal); input tax is
     * the debit movement on PPN Masukan (one line per goods-receipt
     * journal) — both are single aggregated lines per source transaction
     * already, not per product line, so "detail per transaksi" and
     * "detail per jurnal" are the same granularity here.
     *
     * @return array{
     *     start: DateTimeInterface|string, end: DateTimeInterface|string,
     *     output: array{account: array{code: string, name: string}, total: string, details: array},
     *     input: array{account: array{code: string, name: string}, total: string, details: array},
     *     total_payable: string,
     *     is_overpaid: bool,
     * }
     */
    public function ppnReport(DateTimeInterface|string $startDate, DateTimeInterface|string $endDate): array
    {
        $outputAccount = $this->resolveAccount(self::ACCOUNT_PPN_KELUARAN);
        $inputAccount = $this->resolveAccount(self::ACCOUNT_PPN_MASUKAN);

        $output = $this->accountMovement($outputAccount, 'credit', $startDate, $endDate);
        $input = $this->accountMovement($inputAccount, 'debit', $startDate, $endDate);

        $totalPayable = bcsub($output['total'], $input['total'], self::SCALE);

        return [
            'start' => $startDate,
            'end' => $endDate,
            'output' => [
                'account' => ['code' => $outputAccount->code, 'name' => $outputAccount->name],
                'total' => $output['total'],
                'details' => $output['details'],
            ],
            'input' => [
                'account' => ['code' => $inputAccount->code, 'name' => $inputAccount->name],
                'total' => $input['total'],
                'details' => $input['details'],
            ],
            'total_payable' => $totalPayable,
            'is_overpaid' => bccomp($totalPayable, '0', self::SCALE) < 0,
        ];
    }

    /**
     * Resolve an account by its chart-of-accounts code — never a hardcoded
     * ID, consistent with PostingService::getAccount()/resolveAccount().
     */
    private function resolveAccount(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return array{total: string, details: array}
     */
    private function accountMovement(
        Account $account,
        string $column,
        DateTimeInterface|string $startDate,
        DateTimeInterface|string $endDate,
    ): array {
        $rows = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereBetween('journals.date', [$startDate, $endDate])
            ->orderBy('journals.date')
            ->orderBy('journals.id')
            ->get([
                'journals.id as journal_id',
                'journals.number as journal_number',
                'journals.date as date',
                'journals.source_type as source_type',
                'journals.source_id as source_id',
                "journal_lines.{$column} as amount",
            ]);

        $total = $rows->reduce(
            fn ($carry, $row) => bcadd($carry, (string) $row->amount, self::SCALE),
            '0'
        );

        return ['total' => $total, 'details' => $this->attachSourceLabels($rows)];
    }

    /**
     * Batch-load the Sale/GoodsReceipt behind each journal line's polymorphic
     * `source` so the detail rows can show a human-readable transaction
     * label — one query per source type total, not one per row.
     */
    private function attachSourceLabels(Collection $rows): array
    {
        $saleIds = $rows->where('source_type', Sale::class)->pluck('source_id')->unique();
        $receiptIds = $rows->where('source_type', GoodsReceipt::class)->pluck('source_id')->unique();

        $sales = Sale::query()->whereIn('id', $saleIds)->get()->keyBy('id');
        $receipts = GoodsReceipt::query()->whereIn('id', $receiptIds)
            ->with('purchaseOrder.supplier')
            ->get()->keyBy('id');

        return $rows->map(function ($row) use ($sales, $receipts) {
            $label = match ($row->source_type) {
                Sale::class => $this->saleLabel($sales->get($row->source_id), $row->source_id),
                GoodsReceipt::class => $this->receiptLabel($receipts->get($row->source_id), $row->source_id),
                default => "Sumber #{$row->source_id}",
            };

            return [
                'journal_id' => $row->journal_id,
                'journal_number' => $row->journal_number,
                'date' => (string) $row->date,
                'source' => $label,
                'amount' => (string) $row->amount,
            ];
        })->values()->all();
    }

    private function saleLabel(?Sale $sale, int $sourceId): string
    {
        return $sale ? "Penjualan {$sale->local_uuid}" : "Penjualan #{$sourceId}";
    }

    private function receiptLabel(?GoodsReceipt $receipt, int $sourceId): string
    {
        if (! $receipt) {
            return "Penerimaan Barang #{$sourceId}";
        }

        $supplier = $receipt->purchaseOrder?->supplier?->name ?? '-';

        return "Pembelian dari {$supplier} (PO #{$receipt->purchase_order_id})";
    }
}
