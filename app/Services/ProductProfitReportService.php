<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SaleLine;
use App\Models\TaxRate;
use DateTimeInterface;
use Illuminate\Support\Collection;

class ProductProfitReportService
{
    private const SCALE = 4;

    /**
     * Gross profit per product for a date range — net sales minus the HPP
     * (cost of goods sold) actually booked at sale time.
     *
     * Net is re-derived from `sale_lines.line_total` with the IDENTICAL
     * formula and "was this line actually taxed" gate as
     * SalesReportService::lineNetAndTax() — both services read the same
     * rows with the same logic, so this report's total net reconciles with
     * SalesReportService::salesReport()'s total net BY CONSTRUCTION.
     *
     * HPP is NEVER recomputed — it sums `sale_lines.hpp_total`, the exact
     * moving-average cost SaleService::createSaleLine() locked in at the
     * moment of sale (via InventoryService::recordOutbound()). Recomputing
     * HPP from current stock/average cost would make this report drift
     * from the journal (and from history) every time purchase prices
     * change. `SaleService::postSaleJournal()` posts that same
     * `hpp_total` sum to account 5-1000 (HPP), so this report's total HPP
     * reconciles with FinancialReportService::incomeStatement()'s 5-1000
     * balance for the same range BY CONSTRUCTION as well.
     *
     * Only `status = 'completed'` sales count, same as SalesReportService.
     *
     * @param  'gross_profit'|'margin'  $sortBy
     * @return array{
     *     start: DateTimeInterface|string, end: DateTimeInterface|string,
     *     by_product: array, totals: array,
     * }
     */
    public function productProfitReport(
        DateTimeInterface|string $startDate,
        DateTimeInterface|string $endDate,
        string $sortBy = 'gross_profit',
    ): array {
        $byProduct = $this->byProduct($startDate, $endDate, $sortBy);

        $totalNet = $byProduct->reduce(fn ($carry, $row) => bcadd($carry, $row['net'], self::SCALE), '0');
        $totalHpp = $byProduct->reduce(fn ($carry, $row) => bcadd($carry, $row['hpp'], self::SCALE), '0');
        $totalGrossProfit = bcsub($totalNet, $totalHpp, self::SCALE);

        return [
            'start' => $startDate,
            'end' => $endDate,
            'by_product' => $byProduct->values()->all(),
            'totals' => [
                'net' => $totalNet,
                'hpp' => $totalHpp,
                'gross_profit' => $totalGrossProfit,
                'margin' => $this->margin($totalGrossProfit, $totalNet),
            ],
        ];
    }

    /**
     * @param  'gross_profit'|'margin'  $sortBy
     * @return Collection<int, array{product_id: int, product_name: string, qty: string, net: string, hpp: string, gross_profit: string, margin: ?string}>
     */
    private function byProduct(DateTimeInterface|string $startDate, DateTimeInterface|string $endDate, string $sortBy): Collection
    {
        $rows = SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.date', [$startDate, $endDate])
            ->get([
                'sale_lines.product_id',
                'sale_lines.qty',
                'sale_lines.line_total',
                'sale_lines.hpp_total',
                'sale_lines.tax_rate_id',
                'sales.tax_total as sale_tax_total',
            ]);

        $rates = TaxRate::query()->pluck('rate', 'id');

        $aggregated = [];
        foreach ($rows as $row) {
            $net = $this->lineNet($row, $rates);

            $productId = (int) $row->product_id;
            $aggregated[$productId] ??= ['qty' => '0', 'net' => '0', 'hpp' => '0'];
            $aggregated[$productId]['qty'] = bcadd($aggregated[$productId]['qty'], $row->qty, self::SCALE);
            $aggregated[$productId]['net'] = bcadd($aggregated[$productId]['net'], $net, self::SCALE);
            $aggregated[$productId]['hpp'] = bcadd($aggregated[$productId]['hpp'], $row->hpp_total, self::SCALE);
        }

        $productNames = Product::query()->whereIn('id', array_keys($aggregated))->pluck('name', 'id');

        $result = collect($aggregated)
            ->map(function (array $totals, int $productId) use ($productNames) {
                $grossProfit = bcsub($totals['net'], $totals['hpp'], self::SCALE);

                return [
                    'product_id' => $productId,
                    'product_name' => $productNames->get($productId, "Produk #{$productId}"),
                    'qty' => $totals['qty'],
                    'net' => $totals['net'],
                    'hpp' => $totals['hpp'],
                    'gross_profit' => $grossProfit,
                    'margin' => $this->margin($grossProfit, $totals['net']),
                ];
            });

        return $sortBy === 'margin'
            ? $result->sortByDesc(fn (array $row) => $row['margin'] === null ? -INF : (float) $row['margin'])->values()
            : $result->sortByDesc(fn (array $row) => (float) $row['gross_profit'])->values();
    }

    /**
     * Net-only counterpart to SalesReportService::lineNetAndTax() — this
     * report never needs the tax portion, only net, but the taxed/untaxed
     * gate and formula must stay byte-for-byte identical so totals match.
     */
    private function lineNet(object $row, Collection $rates): string
    {
        $wasTaxed = $row->tax_rate_id !== null && bccomp((string) $row->sale_tax_total, '0', self::SCALE) > 0;

        if (! $wasTaxed) {
            return (string) $row->line_total;
        }

        $rate = (string) $rates->get($row->tax_rate_id);
        $divisor = bcadd('1', $rate, self::SCALE);

        return bcdiv((string) $row->line_total, $divisor, self::SCALE);
    }

    /**
     * Margin % = gross profit ÷ net × 100. Guarded against division by
     * zero when net is zero (e.g. a fully-refunded-to-zero edge case, or a
     * product given away) — returns null rather than a misleading number.
     */
    private function margin(string $grossProfit, string $net): ?string
    {
        if (bccomp($net, '0', self::SCALE) === 0) {
            return null;
        }

        return bcmul(bcdiv($grossProfit, $net, self::SCALE + 2), '100', self::SCALE);
    }
}
