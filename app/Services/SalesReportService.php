<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\TaxRate;
use DateTimeInterface;
use Illuminate\Support\Collection;

class SalesReportService
{
    private const SCALE = 4;

    /**
     * Sales report for a date range — daily rollup (Bagian A) and
     * per-product rollup (Bagian B).
     *
     * Bagian A reads `sales.subtotal`/`tax_total`/`grand_total` DIRECTLY —
     * these are the exact figures SaleService::createSale() already posted
     * to the Penjualan/PPN Keluaran accounts, so this section reconciles
     * with FinancialReportService::incomeStatement() and
     * TaxReportService::ppnReport() for the same range BY CONSTRUCTION,
     * with zero recomputation and zero rounding risk.
     *
     * Bagian B needs product-level granularity, which `sales` alone can't
     * give — it re-derives each line's net/tax from `sale_lines.line_total`
     * (inclusive) using the IDENTICAL formula and scale as
     * SaleService::createSaleLine() (net = inclusive ÷ (1+rate) truncated
     * scale 4, tax = inclusive − net), so the sum across all products is
     * guaranteed to equal Bagian A's total exactly — see
     * lineNetAndTax() for why the per-line "was this actually taxed"
     * decision is safe to reconstruct from stored columns alone.
     *
     * Only `status = 'completed'` sales count — void/refunded (schema
     * already has the enum, no such flow exists yet) are deliberately
     * excluded so this report never has to be revisited when that ships.
     *
     * @return array{
     *     start: DateTimeInterface|string, end: DateTimeInterface|string,
     *     by_day: array, by_product: array,
     *     totals: array{transaction_count: int, gross: string, net: string, tax: string},
     * }
     */
    public function salesReport(DateTimeInterface|string $startDate, DateTimeInterface|string $endDate): array
    {
        $byDay = $this->byDay($startDate, $endDate);
        $byProduct = $this->byProduct($startDate, $endDate);

        $totals = [
            'transaction_count' => (int) $byDay->sum('transaction_count'),
            'gross' => $byDay->reduce(fn ($carry, $row) => bcadd($carry, $row['gross'], self::SCALE), '0'),
            'net' => $byDay->reduce(fn ($carry, $row) => bcadd($carry, $row['net'], self::SCALE), '0'),
            'tax' => $byDay->reduce(fn ($carry, $row) => bcadd($carry, $row['tax'], self::SCALE), '0'),
        ];

        return [
            'start' => $startDate,
            'end' => $endDate,
            'by_day' => $byDay->values()->all(),
            'by_product' => $byProduct->values()->all(),
            'totals' => $totals,
        ];
    }

    /**
     * @return Collection<int, array{date: string, transaction_count: int, gross: string, net: string, tax: string}>
     */
    private function byDay(DateTimeInterface|string $startDate, DateTimeInterface|string $endDate): Collection
    {
        return Sale::query()
            ->where('status', 'completed')
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw('date, COUNT(*) as transaction_count, SUM(grand_total) as gross, SUM(subtotal) as net, SUM(tax_total) as tax')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date->toDateString(),
                'transaction_count' => (int) $row->transaction_count,
                'gross' => (string) $row->gross,
                'net' => (string) $row->net,
                'tax' => (string) $row->tax,
            ]);
    }

    /**
     * @return Collection<int, array{product_id: int, product_name: string, qty: string, gross: string, net: string, tax: string}>
     */
    private function byProduct(DateTimeInterface|string $startDate, DateTimeInterface|string $endDate): Collection
    {
        $rows = SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.date', [$startDate, $endDate])
            ->get([
                'sale_lines.product_id',
                'sale_lines.qty',
                'sale_lines.line_total',
                'sale_lines.tax_rate_id',
                'sales.tax_total as sale_tax_total',
            ]);

        $rates = TaxRate::query()->pluck('rate', 'id');

        $aggregated = [];
        foreach ($rows as $row) {
            [$net, $tax] = $this->lineNetAndTax($row, $rates);

            $productId = (int) $row->product_id;
            $aggregated[$productId] ??= ['qty' => '0', 'gross' => '0', 'net' => '0', 'tax' => '0'];
            $aggregated[$productId]['qty'] = bcadd($aggregated[$productId]['qty'], $row->qty, self::SCALE);
            $aggregated[$productId]['gross'] = bcadd($aggregated[$productId]['gross'], $row->line_total, self::SCALE);
            $aggregated[$productId]['net'] = bcadd($aggregated[$productId]['net'], $net, self::SCALE);
            $aggregated[$productId]['tax'] = bcadd($aggregated[$productId]['tax'], $tax, self::SCALE);
        }

        $productNames = Product::query()->whereIn('id', array_keys($aggregated))->pluck('name', 'id');

        return collect($aggregated)
            ->map(fn (array $totals, int $productId) => [
                'product_id' => $productId,
                'product_name' => $productNames->get($productId, "Produk #{$productId}"),
                'qty' => $totals['qty'],
                'gross' => $totals['gross'],
                'net' => $totals['net'],
                'tax' => $totals['tax'],
            ])
            // Paling laku = kontribusi omzet (nilai bruto) terbesar, bukan qty —
            // lebih relevan untuk pemilik toko daripada sekadar jumlah unit.
            ->sortByDesc(fn (array $row) => (float) $row['gross'])
            ->values();
    }

    /**
     * Re-derive one line's net/tax from stored columns alone — safe even
     * though `sale_lines.tax_rate_id` records the PRODUCT's nominal rate
     * regardless of whether the global PPN switch was on when this sale
     * was created (see SaleService::createSaleLine()'s own docblock).
     *
     * The switch being off forces EVERY line of that sale to have zero tax
     * (SaleService passes the same $ppnActive to every line), so
     * `sales.tax_total` for the whole sale is strictly zero whenever the
     * switch was off — there is no other way for it to be zero while a
     * taxable line exists. That makes `sale_tax_total > 0` a reliable
     * per-sale proxy for "the switch was on for this sale", and combined
     * with a non-null `tax_rate_id` on this specific line, an exact
     * reconstruction of the original per-line branch in createSaleLine().
     *
     * @return array{0: string, 1: string} [net, tax]
     */
    private function lineNetAndTax(object $row, Collection $rates): array
    {
        $wasTaxed = $row->tax_rate_id !== null && bccomp((string) $row->sale_tax_total, '0', self::SCALE) > 0;

        if (! $wasTaxed) {
            return [(string) $row->line_total, '0'];
        }

        $rate = (string) $rates->get($row->tax_rate_id);
        $divisor = bcadd('1', $rate, self::SCALE);
        $net = bcdiv((string) $row->line_total, $divisor, self::SCALE);
        $tax = bcsub((string) $row->line_total, $net, self::SCALE);

        return [$net, $tax];
    }
}
