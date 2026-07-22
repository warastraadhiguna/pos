<?php

namespace App\Services;

use App\Exceptions\CashierMismatchException;
use App\Exceptions\InsufficientCashReceivedException;
use App\Exceptions\UnreconciledChangeAmountException;
use App\Exceptions\UnreconciledSaleTotalException;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaleService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by Database\Seeders\FoundationSeeder.
    private const ACCOUNT_PERSEDIAAN = '1-1200';

    private const ACCOUNT_PPN_KELUARAN = '2-1100';

    private const ACCOUNT_PENJUALAN = '4-1000';

    private const ACCOUNT_HPP = '5-1000';

    /**
     * The unique index Laravel generated for sales.local_uuid (verified via
     * `SHOW INDEX FROM sales`). Used to identify a local_uuid collision
     * precisely, as opposed to any other constraint violation — same
     * discipline as PostingService::NUMBER_UNIQUE_INDEX.
     */
    private const LOCAL_UUID_UNIQUE_INDEX = 'sales_local_uuid_unique';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Create a POS sale end to end: sale + sale_lines, deduct BOM component
     * stock (Moving Average via InventoryService), and post the balanced
     * sales journal (PostingService) — all inside one DB transaction.
     *
     * Idempotent on local_uuid: this is the field an offline-first mobile
     * client generates before it ever talks to the server, so the same sale
     * can be retried safely after a dropped connection. If a Sale with the
     * given local_uuid already exists, it's returned as-is — nothing is
     * re-processed (no double stock deduction, no duplicate journal). The
     * returned Sale's wasReplayed flag tells the caller which happened,
     * purely for telemetry — callers should treat both cases as success.
     *
     * Two-layer guarantee (same discipline as PostingService's journal
     * numbering): a plain SELECT handles the common case cheaply (retry
     * after the original request already committed), and the database's
     * unique constraint on sales.local_uuid is the real atomicity guarantee
     * for two requests that race past that SELECT at nearly the same time —
     * whichever INSERT loses is caught here and converted into "return the
     * winner's row", never a duplicate and never an error to the caller.
     *
     * Prices are tax-inclusive: `unit_price` is what the customer actually
     * pays per unit, PPN already baked in. A global switch
     * (`CompanySetting::current()->ppn_active`, PKP vs non-PKP) plus each
     * product's own nullable `tax_rate` decide whether PPN is extracted
     * FROM that inclusive price for a given line — never added on top.
     * See createSaleLine() for the exact extraction formula.
     *
     * @param  array{
     *     outlet_id: int,
     *     warehouse_id: int,
     *     date: \DateTimeInterface|string,
     *     payment_method?: string,
     *     cash_account_code?: string,
     *     local_uuid?: string,
     *     created_by_user_id?: int|null,
     *     client_user_id?: int|null,
     *     device_label?: string|null,
     *     cash_received?: int|float|string,
     *     change_amount?: int|float|string,
     *     lines: array<int, array{product_id: int, qty: int|float|string, unit_price: int|float|string}>,
     * }  $data
     *
     * cash_received/change_amount are OPTIONAL — only the mobile POS
     * checkout (Api\SaleController) tracks tendered cash; the web Kasir
     * flow (Kasir\SaleController) never sends them. When absent, this
     * defaults to "paid exactly, no change" (cash_received = grand_total,
     * change_amount = 0) and skips validation entirely — a caller that
     * doesn't track cash shouldn't be forced to reconcile it. When
     * PRESENT, it's validated strictly: cash_received must cover
     * grand_total, and change_amount must equal the server's own
     * recomputation of cash_received − grand_total (never trust the
     * caller's arithmetic — same discipline as the subtotal/tax/grand_total
     * check below).
     *
     * cash_account_code is likewise OPTIONAL -- which Kas/Bank account
     * actually received this sale's money (see CashAccountService). When
     * absent, defaults to Kas (CashAccountService::DEFAULT_CODE). The
     * mobile POS API never sends this at all (it has no UI for it by
     * design -- every mobile sale is physically cash-in-hand today, since
     * payment_method there is locked to 'cash' with no other tender), so
     * every mobile sale always lands on Kas via this same default; only
     * the web Kasir flow ever sends a non-default value.
     *
     * client_user_id is likewise OPTIONAL and, when present, likewise never
     * trusted blindly — see CashierMismatchException's docblock. It exists
     * to catch the offline-first mobile scenario where a sale is created
     * under one cashier's login and only pushed to the server after a
     * DIFFERENT cashier has since logged in on the same device: without
     * this check, created_by_user_id (always resolved from the token that
     * authenticates THIS request, never from client input) would silently
     * misattribute the sale to whoever happens to be logged in at push
     * time, not whoever actually rang it up. When absent (older mobile
     * clients, or the web Kasir flow which never sends it), there is
     * nothing to cross-check against, so this falls back to today's
     * behaviour unchanged: trust created_by_user_id as-is.
     */
    public function createSale(array $data): Sale
    {
        $localUuid = $data['local_uuid'] ?? null;

        if ($localUuid && ($existing = Sale::where('local_uuid', $localUuid)->first())) {
            $existing->wasReplayed = true;

            return $existing->load('lines');
        }

        // Cross-check ONLY on first creation — a replay (handled above)
        // never re-attributes anything, so a mismatched client_user_id on
        // a retry/replay is simply irrelevant and must never turn an
        // already-successful idempotent replay into a rejection.
        $clientUserId = $data['client_user_id'] ?? null;
        if ($clientUserId !== null && $clientUserId !== ($data['created_by_user_id'] ?? null)) {
            throw new CashierMismatchException(
                "client_user_id ({$clientUserId}) tidak cocok dengan pemilik token yang mengautentikasi request ini".
                (isset($data['created_by_user_id']) ? " (user #{$data['created_by_user_id']})" : '').
                ' — transaksi TIDAK dibuat, menunggu kasir yang benar login untuk mengirim ulang.'
            );
        }

        return DB::transaction(function () use ($data, $localUuid) {
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);
            $paymentMethod = $data['payment_method'] ?? 'cash';
            $cashAccountCode = $data['cash_account_code'] ?? CashAccountService::DEFAULT_CODE;
            $this->cashAccounts->assertValidCashAccount($cashAccountCode);

            // Konversi EKSPLISIT ke WIB di sini, satu kali, dipakai untuk
            // SEMUA turunan tanggal di bawah (sales.date, sales.occurred_at,
            // journal, stock movement) — supaya benar by design, bukan
            // kebetulan. Ini aman untuk ketiga bentuk input $data['date']:
            //   - web Kasir: Carbon `now()` (sudah WIB sejak config('app.timezone')
            //     = Asia/Jakarta) -> setTimezone jadi no-op.
            //   - HP versi baru: string ISO ber-'Z' (UTC eksplisit) -> dikonversi
            //     sungguhan ke WIB.
            //   - HP versi lama (sebelum diperbaiki): string ISO TANPA offset
            //     (kuirk Dart) -> Carbon menafsirkannya di timezone default PHP,
            //     yang sekarang Asia/Jakarta juga -- sudah WIB, jadi tetap benar.
            $occurredAt = Carbon::parse($data['date'])->setTimezone('Asia/Jakarta');

            $sale = new Sale([
                'outlet_id' => $data['outlet_id'],
                'warehouse_id' => $data['warehouse_id'],
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'date' => $occurredAt,
                'occurred_at' => $occurredAt,
                'local_uuid' => $localUuid ?? (string) Str::uuid(),
                'device_label' => $data['device_label'] ?? null,
                'payment_method' => $paymentMethod,
                'cash_account_code' => $cashAccountCode,
                'status' => 'completed',
                'subtotal' => '0',
                'tax_total' => '0',
                'grand_total' => '0',
            ]);

            try {
                $sale->save();
            } catch (QueryException $e) {
                // Unique constraint race: another request for the same
                // local_uuid was inserted between our existence check above
                // and this insert. Treat it the same as the pre-check hit.
                if ($localUuid && $this->isDuplicateLocalUuid($e)) {
                    $existing = Sale::where('local_uuid', $localUuid)->firstOrFail();
                    $existing->wasReplayed = true;

                    return $existing->load('lines');
                }

                throw $e;
            }

            $ppnActive = CompanySetting::current()->ppn_active;

            $subtotal = '0';
            $taxTotal = '0';
            $grandTotal = '0';
            $hppGrandTotal = '0';

            foreach ($data['lines'] as $lineData) {
                [$lineNet, $lineTax, $lineInclusive, $hppLineTotal] = $this->createSaleLine(
                    $sale, $warehouse, $lineData, $occurredAt, $ppnActive,
                );

                $subtotal = bcadd($subtotal, $lineNet, self::SCALE);
                $taxTotal = bcadd($taxTotal, $lineTax, self::SCALE);
                $grandTotal = bcadd($grandTotal, $lineInclusive, self::SCALE);
                $hppGrandTotal = bcadd($hppGrandTotal, $hppLineTotal, self::SCALE);
            }

            // Jaring pengaman: subtotal + tax_total HARUS eksak sama dengan
            // grand_total (jumlah harga inclusive tiap baris). Ini harus
            // selalu benar by construction (createSaleLine menghitung tax
            // lewat pengurangan, bukan perkalian independen) — kalau
            // sampai tidak sama, itu bug di rumus per baris, bukan sesuatu
            // yang boleh diam-diam ditoleransi.
            $reconciled = bcadd($subtotal, $taxTotal, self::SCALE);
            if (bccomp($reconciled, $grandTotal, self::SCALE) !== 0) {
                throw new UnreconciledSaleTotalException(
                    "Subtotal ({$subtotal}) + tax_total ({$taxTotal}) = {$reconciled} tidak sama dengan grand_total ({$grandTotal})."
                );
            }

            // Lihat docblock method ini: opsional, cuma divalidasi kalau
            // caller (mobile POS) benar-benar mengirimkannya.
            if (array_key_exists('cash_received', $data)) {
                $cashReceived = (string) $data['cash_received'];
                $changeAmount = (string) ($data['change_amount'] ?? '0');

                if (bccomp($cashReceived, $grandTotal, self::SCALE) < 0) {
                    throw new InsufficientCashReceivedException(
                        "Uang diterima ({$cashReceived}) kurang dari grand_total ({$grandTotal})."
                    );
                }

                $expectedChange = bcsub($cashReceived, $grandTotal, self::SCALE);
                if (bccomp($expectedChange, $changeAmount, self::SCALE) !== 0) {
                    throw new UnreconciledChangeAmountException(
                        "change_amount ({$changeAmount}) tidak sama dengan cash_received − grand_total ({$expectedChange})."
                    );
                }
            } else {
                $cashReceived = $grandTotal;
                $changeAmount = '0';
            }

            $sale->update([
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'cash_received' => $cashReceived,
                'change_amount' => $changeAmount,
            ]);

            $this->postSaleJournal($sale, $subtotal, $taxTotal, $grandTotal, $hppGrandTotal, $occurredAt, $cashAccountCode);

            $freshSale = $sale->fresh('lines');
            $freshSale->wasReplayed = false;

            return $freshSale;
        });
    }

    /**
     * True only for a duplicate-entry violation on sales.local_uuid
     * specifically — same discipline as
     * PostingService::isDuplicateJournalNumber(): SQLSTATE 23000 plus MySQL
     * error code 1062 alone isn't enough (any other unique constraint on
     * the table would share both), so this also checks that the named
     * unique index is the one on local_uuid. Verified via
     * `SHOW INDEX FROM sales` — it is currently the only unique index
     * besides the primary key, but this check stays index-specific so a
     * future unique constraint on the table can never be misattributed as
     * a local_uuid race.
     */
    private function isDuplicateLocalUuid(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($e->errorInfo[2] ?? ''), self::LOCAL_UUID_UNIQUE_INDEX);
    }

    /**
     * Extracts PPN from a tax-inclusive line price rather than adding it on
     * top. For a line whose price already includes tax at rate r:
     *
     *     line_net = line_inclusive ÷ (1 + r)     [bcdiv, truncated scale 4]
     *     line_tax = line_inclusive − line_net    [bcsub, NOT line_net × r]
     *
     * Computing line_tax by subtraction (not by an independent
     * multiplication) guarantees line_net + line_tax reconstructs
     * line_inclusive exactly — no rounding residue from double-rounding
     * two separately-truncated bcmath results.
     *
     * No extraction happens (line_net = line_inclusive, line_tax = '0')
     * when either the global PPN switch is off or this product has no
     * tax_rate — both collapse to the same "not taxed this time" case.
     * `tax_rate_id` is still stored on the line as the product's nominal
     * rate regardless of the switch, so a zero-tax line stays auditable
     * (was it untaxed because the product isn't taxable, or because the
     * switch was off?).
     *
     * @param  array{product_id: int, product_name?: ?string, qty: int|float|string, unit_price: int|float|string}  $lineData
     * @return array{0: string, 1: string, 2: string, 3: string} [line_net, line_tax, line_inclusive, hpp_total]
     */
    private function createSaleLine(Sale $sale, Warehouse $warehouse, array $lineData, \DateTimeInterface|string $date, bool $ppnActive): array
    {
        $product = Product::with(['components.item', 'components.uom', 'taxRate'])
            ->findOrFail($lineData['product_id']);

        $qty = (string) $lineData['qty'];
        $unitPrice = (string) $lineData['unit_price'];
        $lineInclusive = bcmul($qty, $unitPrice, self::SCALE);

        // Snapshot nama produk SAAT transaksi. Kalau caller sudah tahu nilainya
        // sendiri (mis. mobile offline yang menyimpan snapshot lokal pada momen
        // penjualan SUNGGUHAN terjadi), pakai apa adanya -- itu satu-satunya
        // sumber yang benar untuk kasus rename-saat-offline. Kalau tidak
        // diberikan (web real-time, atau mobile versi yang belum mengirim
        // field ini), pakai nama produk saat ini: benar untuk jalur real-time
        // karena tidak ada jeda waktu antara transaksi dan penyimpanan.
        $productName = trim((string) ($lineData['product_name'] ?? '')) !== ''
            ? $lineData['product_name']
            : $product->name;

        $effectiveRate = ($ppnActive && $product->taxRate)
            ? (string) $product->taxRate->rate
            : null;

        if ($effectiveRate !== null) {
            $divisor = bcadd('1', $effectiveRate, self::SCALE);
            $lineNet = bcdiv($lineInclusive, $divisor, self::SCALE);
            $lineTax = bcsub($lineInclusive, $lineNet, self::SCALE);
        } else {
            $lineNet = $lineInclusive;
            $lineTax = '0';
        }

        $hppLineTotal = '0';

        foreach ($product->components as $component) {
            $componentQty = bcmul((string) $component->qty, $qty, self::SCALE);
            $componentQtyInBaseUom = $this->inventory->convertToItemBaseUom($component->item, $component->uom, $componentQty);

            $hpp = $this->inventory->recordOutbound(
                $component->item,
                $warehouse,
                $componentQtyInBaseUom,
                $sale,
                $date,
            );

            $hppLineTotal = bcadd($hppLineTotal, $hpp, self::SCALE);
        }

        $sale->lines()->create([
            'product_id' => $product->id,
            'product_name' => $productName,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate_id' => $product->tax_rate_id,
            'line_total' => $lineInclusive,
            'hpp_total' => $hppLineTotal,
        ]);

        return [$lineNet, $lineTax, $lineInclusive, $hppLineTotal];
    }

    private function postSaleJournal(
        Sale $sale,
        string $subtotal,
        string $taxTotal,
        string $grandTotal,
        string $hppTotal,
        \DateTimeInterface|string $date,
        string $cashAccountCode,
    ): void {
        $lines = [];

        if (bccomp($grandTotal, '0', self::SCALE) !== 0) {
            // Which Kas/Bank account receives the money -- see
            // CashAccountService. For now every payment method still
            // settles to a Kas/Bank account; extend this once non-cash
            // tenders (card, e-wallet, piutang) are introduced.
            $lines[] = ['account' => $cashAccountCode, 'debit' => $grandTotal, 'credit' => 0];
        }

        if (bccomp($subtotal, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => self::ACCOUNT_PENJUALAN, 'debit' => 0, 'credit' => $subtotal];
        }

        if (bccomp($taxTotal, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => self::ACCOUNT_PPN_KELUARAN, 'debit' => 0, 'credit' => $taxTotal];
        }

        if (bccomp($hppTotal, '0', self::SCALE) !== 0) {
            $lines[] = ['account' => self::ACCOUNT_HPP, 'debit' => $hppTotal, 'credit' => 0];
            $lines[] = ['account' => self::ACCOUNT_PERSEDIAAN, 'debit' => 0, 'credit' => $hppTotal];
        }

        $this->posting->post(
            lines: $lines,
            date: $date,
            source: $sale,
            memo: "Penjualan {$sale->local_uuid}",
        );
    }
}
