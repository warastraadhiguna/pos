<?php

namespace App\Services;

use App\Exceptions\CashierMismatchException;
use App\Exceptions\InsufficientCashReceivedException;
use App\Exceptions\InvalidQrisAccountException;
use App\Exceptions\UnreconciledChangeAmountException;
use App\Exceptions\UnreconciledSaleTotalException;
use App\Models\CompanySetting;
use App\Models\DiningTable;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use App\Models\SaleLine;
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
        private readonly DraftSyncService $drafts,
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
     *     member_id?: int|null,
     *     member_name?: string|null,
     *     table_id?: int|null,
     *     table_name?: string|null,
     *     note?: string|null,
     *     lines: array<int, array{product_id: int, qty: int|float|string, unit_price: int|float|string, note?: string|null, variations?: array<int, array{variation_id: int, name?: string|null, price?: int|float|string|null}>}>,
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
     * BOTH are IGNORED entirely when payment_method is 'qris' — QRIS is
     * always paid exactly via scan, so this always forces cash_received =
     * grand_total / change_amount = 0 regardless of what the caller sends
     * (or doesn't), skipping the reconciliation checks above rather than
     * failing a fully-paid QRIS sale over a client/server total mismatch
     * (see rancangan fitur QRIS).
     *
     * cash_account_code is likewise OPTIONAL -- which Kas/Bank account
     * actually received this sale's money (see CashAccountService). When
     * absent AND payment_method is 'cash' (or anything else that isn't
     * 'qris'), defaults to this sale's OWN outlet's Kas account (Multi-
     * Cabang Lapisan 3, see CashAccountService::resolveCashAccountCodeForOutlet())
     * -- the pusat outlet resolves to the same global Kas
     * (CashAccountService::DEFAULT_CODE) as before Lapisan 3 existed, so
     * single-location shops (multi_branch_enabled=false, every sale
     * resolves to pusat) see byte-identical behaviour. The mobile POS API
     * never sends this at all for cash sales (no UI for it by design,
     * every mobile cash sale is physically cash-in-hand), so it always
     * lands on this outlet-resolved default; only the web Kasir flow ever
     * sends a non-default value there.
     *
     * When absent AND payment_method is 'qris' (Tafsir A -- pencatatan,
     * lihat rancangan fitur QRIS), the default is instead the Bank account
     * configured in Pengaturan (CompanySetting::qris_cash_account_code),
     * NEVER Kas -- the mobile POS API has no account picker for QRIS
     * either, so every mobile QRIS sale resolves through this same
     * settings-driven default; the web Kasir flow sends an explicit Bank
     * account picked from CashAccountService::selectableBankAccounts()
     * instead. Either way, a 'qris' sale whose resolved account turns out
     * to be Kas throws InvalidQrisAccountException — this is a hard
     * invariant, not a preference: QRIS money always lands in a bank
     * account, never physically in the cash drawer.
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
     *
     * member_id/member_name are both OPTIONAL and independent, same as the
     * product_name snapshot pattern in createSaleLine(): member_id links the
     * sale to a real Member row (for future per-member history and the
     * upcoming draft feature — see Member model docblock), while
     * member_name_snapshot is what actually gets frozen and shown on every
     * past receipt forever, regardless of whether that Member is later
     * renamed or deactivated. When member_id is given but member_name is
     * not, the Member's current name is snapshotted (real-time web Kasir
     * case — no time gap between pick and save). When member_name is given
     * explicitly, it always wins (offline mobile case: the name as it was
     * at the moment the cashier actually typed/picked it, which may predate
     * a rename that happened before this sale was later pushed to the
     * server). A cashier may also type a name with no member_id at all —
     * that's a valid walk-in customer, not an error. When neither is given,
     * the sale simply has no customer attached, and every receipt line for
     * "Pelanggan: ..." is skipped entirely.
     *
     * table_id/table_name follow the EXACT same shape and reasoning as
     * member_id/member_name (see above) — table_id links the sale to a
     * real DiningTable row (for the future draft feature, which reuses
     * this same table_id column), while table_name_snapshot is what
     * actually gets frozen and shown on the receipt, unaffected by later
     * renaming/deactivating that table. A cashier may also type a table
     * name with no table_id at all (mis. "meja tambahan di luar" yang
     * belum terdaftar) — valid, not an error. When neither is given, no
     * "Meja: ..." line appears on the receipt.
     *
     * note (per-sale) and lines[].note (per-line) are BOTH plain optional
     * strings, stored exactly as given — unlike member/table there is no
     * paired `_id` to resolve here, because a note isn't a reference to
     * another entity that can later be renamed; whatever text the cashier
     * typed or picked from a template (then possibly edited) IS already
     * the correct permanent record. Empty/absent means the "Catatan"
     * line (per-sale) or "→ ..." line (per-item) is skipped on the
     * receipt entirely.
     *
     * lines[].variations — Variasi Berbayar. KRUSIAL: this does NOT change
     * how lineInclusive/lineNet/lineTax are computed below — `unit_price`
     * sent by the caller is trusted exactly as it already was BEFORE this
     * feature existed (this method has never cross-checked unit_price
     * against Product::sell_price; see the qty/unitPrice lines in
     * createSaleLine()). The caller (web Kasir / mobile) is expected to
     * have already folded every selected variation's additional_price
     * into that unit_price client-side, the same way it already folds in
     * nothing else today — variations never affect PRICE math here.
     *
     * They DO affect HPP (Tahap 2): each entry's `variation_id` is
     * validated to belong to this line's product, then frozen into
     * `sale_line_variations` with `hpp_snapshot` set from that variation's
     * OWN BOM (`product_variation_components`, consumed via
     * `InventoryService::recordOutbound()` — see
     * consumeSaleLineVariations()), folded into this line's `hpp_total`
     * alongside the product's own components. A variation with no BOM rows
     * still gets `hpp_snapshot = '0'`, identical to Tahap 1's behaviour —
     * old rows created before Tahap 2 existed keep their frozen '0'
     * forever, untouched by this method. `name`/`price` follow the exact
     * same optional-override shape as member_name/table_name: given
     * explicitly, they win (offline mobile: the values as picked at the
     * moment of sale, which may predate a server-side rename); absent,
     * they're resolved from the ProductVariation row live (real-time web
     * Kasir case).
     */
    public function createSale(array $data): Sale
    {
        $localUuid = $data['local_uuid'] ?? null;

        if ($localUuid && ($existing = Sale::where('local_uuid', $localUuid)->first())) {
            $existing->wasReplayed = true;

            return $existing->load('lines.variations');
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

            // QRIS (Tafsir A -- pencatatan murni, lihat rancangan fitur
            // QRIS): caller yang TIDAK mengirim cash_account_code sama
            // sekali (mobile POS -- tidak punya UI pemilih akun apa pun,
            // lihat docblock method ini) jatuh ke akun Bank default yang
            // diatur di Pengaturan, BUKAN ke Kas seperti metode lain.
            // Caller yang MEMANG mengirimnya (web Kasir, lewat picker
            // "Masuk Ke" yang dibatasi ke akun Bank saat QRIS dipilih)
            // tetap dipakai apa adanya -- divalidasi di bawah, sama-sama
            // ditolak kalau ternyata Kas.
            if ($paymentMethod === 'qris' && ! array_key_exists('cash_account_code', $data)) {
                $cashAccountCode = CompanySetting::current()->qris_cash_account_code
                    ?? throw new InvalidQrisAccountException(
                        'Metode QRIS memerlukan akun Bank tujuan -- atur dulu di Pengaturan.'
                    );
            } else {
                // Multi-Cabang Lapisan 3 -- caller yang TIDAK mengirim
                // cash_account_code sama sekali (mobile POS -- selalu,
                // web Kasir kalau kasirnya tidak memilih akun tertentu)
                // jatuh ke akun Kas CABANG penjualan ini (lihat
                // CashAccountService::resolveCashAccountCodeForOutlet()),
                // BUKAN selalu Kas pusat seperti sebelum Lapisan 3 --
                // cabang pusat sendiri tetap Kas global apa adanya
                // (method itu mengembalikan DEFAULT_CODE untuk pusat),
                // jadi perilaku pusat 100% tidak berubah.
                $cashAccountCode = $data['cash_account_code']
                    ?? $this->cashAccounts->resolveCashAccountCodeForOutlet($warehouse->outlet);
            }

            $this->cashAccounts->assertValidCashAccount($cashAccountCode);

            // Inti fitur QRIS: uangnya SELALU mendarat di rekening bank,
            // tidak pernah fisik di laci tunai -- lihat docblock
            // InvalidQrisAccountException.
            if ($paymentMethod === 'qris' && $cashAccountCode === CashAccountService::DEFAULT_CODE) {
                throw new InvalidQrisAccountException(
                    "Metode QRIS tidak boleh masuk ke akun Kas ({$cashAccountCode}) -- pilih akun Bank tujuan."
                );
            }

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

            $member = ! empty($data['member_id']) ? Member::findOrFail($data['member_id']) : null;
            $memberNameSnapshot = trim((string) ($data['member_name'] ?? '')) !== ''
                ? $data['member_name']
                : $member?->name;

            $table = ! empty($data['table_id']) ? DiningTable::findOrFail($data['table_id']) : null;
            $tableNameSnapshot = trim((string) ($data['table_name'] ?? '')) !== ''
                ? $data['table_name']
                : $table?->name;

            $sale = new Sale([
                'outlet_id' => $data['outlet_id'],
                'warehouse_id' => $data['warehouse_id'],
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'member_id' => $member?->id,
                'member_name_snapshot' => $memberNameSnapshot,
                'table_id' => $table?->id,
                'table_name_snapshot' => $tableNameSnapshot,
                'note' => trim((string) ($data['note'] ?? '')) !== '' ? $data['note'] : null,
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

                    return $existing->load('lines.variations');
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

            if ($paymentMethod === 'qris') {
                // QRIS dibayar PAS lewat scan -- tidak ada konsep uang
                // diterima/kembalian sama sekali (lihat rancangan fitur
                // QRIS). Apa pun yang caller kirim di cash_received/
                // change_amount (kalau ada) diabaikan sepenuhnya di sini,
                // BUKAN divalidasi seperti cabang cash di bawah -- selisih
                // penghitungan grand_total klien vs server tidak boleh
                // menggagalkan transaksi QRIS yang sudah tuntas dibayar.
                $cashReceived = $grandTotal;
                $changeAmount = '0';
            } elseif (array_key_exists('cash_received', $data)) {
                // Lihat docblock method ini: opsional, cuma divalidasi kalau
                // caller (mobile POS) benar-benar mengirimkannya.
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

            // Langkah 3 fitur Draft: kalau sale ini finalisasi sebuah draft
            // (mobile mengirim `draft_local_uuid`), tandai draft itu
            // 'finalized' DALAM transaksi yang SAMA -- satu commit atomik,
            // tidak pernah ada window "sale sudah tersimpan tapi status
            // draft ketinggalan" akibat dua request terpisah yang salah
            // satunya gagal sendirian. No-op diam-diam kalau uuid tidak
            // dikenali (lihat docblock DraftSyncService::finalizeByLocalUuid()).
            $this->drafts->finalizeByLocalUuid($data['draft_local_uuid'] ?? null);

            $freshSale = $sale->fresh('lines.variations');
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
     * @param  array{product_id: int, product_name?: ?string, qty: int|float|string, unit_price: int|float|string, note?: ?string, variations?: array<int, array{variation_id: int, name?: ?string, price?: int|float|string|null}>}  $lineData
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

        // Tahap 2: konsumsi BOM tiap variasi terpilih (kalau ada) DULU, di
        // sini -- SEBELUM sale_lines dibuat -- supaya hpp_total baris sudah
        // benar (produk + Σ variasi) sejak baris pertama kali ditulis,
        // bukan lewat UPDATE susulan. Lihat consumeSaleLineVariations().
        [$variationRows, $variationHppTotal] = $this->consumeSaleLineVariations(
            $sale, $warehouse, $product, $qty, $lineData['variations'] ?? [], $date,
        );
        $hppLineTotal = bcadd($hppLineTotal, $variationHppTotal, self::SCALE);

        $saleLine = $sale->lines()->create([
            'product_id' => $product->id,
            'product_name' => $productName,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate_id' => $product->tax_rate_id,
            'line_total' => $lineInclusive,
            'hpp_total' => $hppLineTotal,
            'note' => trim((string) ($lineData['note'] ?? '')) !== '' ? $lineData['note'] : null,
        ]);

        foreach ($variationRows as $row) {
            $saleLine->variations()->create($row);
        }

        return [$lineNet, $lineTax, $lineInclusive, $hppLineTotal];
    }

    /**
     * Tahap 2 dari fitur Variasi Berbayar -- konsumsi BOM tiap variasi
     * terpilih (kalau ada) lewat `InventoryService::recordOutbound()`, PERSIS
     * jalur yang sama dipakai komponen BOM produk beberapa baris di atas
     * (Moving Average, `lockLedger()` per item+warehouse yang sama -- tidak
     * ada mekanisme konkurensi baru). Variasi tanpa baris di
     * `product_variation_components` (kolom `components` kosong) -> HPP-nya
     * '0', identik perilaku Tahap 1.
     *
     * Movement di-source ke `$sale` (Sale utuh), BUKAN ke sale_line/
     * sale_line_variation -- konsisten dengan bagaimana movement komponen
     * PRODUK di atas juga di-source ke `$sale`, bukan ke sale_line. Satu
     * transaksi penjualan = satu dokumen sumber untuk SEMUA stock_movements
     * yang ditimbulkannya, terlepas dari baris/variasi mana penyebabnya --
     * `Sale::stockMovements()` (morphMany) tetap satu-satunya jalur query,
     * tanpa perlu tahu dua polymorphic type berbeda.
     *
     * Stok bahan variasi yang tidak cukup TIDAK ditolak dan TIDAK
     * menghasilkan peringatan apa pun di sini -- `recordOutbound()` sendiri
     * tidak pernah mengecek `running_qty >= qty` untuk komponen produk
     * (lihat docblock InventoryService, stok minus disengaja diizinkan,
     * lihat docs/ROADMAP.md), jadi komponen variasi mengikuti PERSIS
     * perilaku yang sama -- tidak ada aturan baru/berbeda untuk variasi.
     *
     * HPP dihitung 100% di server dari harga rata-rata bergerak SAAT
     * transaksi -- mobile/web TIDAK PERNAH mengirim atau tahu angka HPP
     * variasi, sama seperti mobile/web tidak pernah tahu HPP komponen
     * produk hari ini.
     *
     * hpp_snapshot dari transaksi Tahap 1 LAMA (dibuat sebelum kolom
     * `product_variation_components` ada sama sekali) TIDAK PERNAH disentuh
     * ulang oleh method ini -- method ini hanya jalan untuk transaksi BARU,
     * baris lama tetap '0' selamanya (murni angka historis beku, sama
     * seperti product_name/member_name_snapshot).
     *
     * @param  array<int, array{variation_id: int, name?: ?string, price?: int|float|string|null}>  $variations
     * @return array{0: array<int, array{variation_id: int, name_snapshot: string, price_snapshot: string, hpp_snapshot: string}>, 1: string} [rows to insert once sale_line exists, total variation HPP for this line]
     */
    private function consumeSaleLineVariations(
        Sale $sale,
        Warehouse $warehouse,
        Product $product,
        string $qty,
        array $variations,
        \DateTimeInterface|string $date,
    ): array {
        $rows = [];
        $totalHpp = '0';

        foreach ($variations as $variationData) {
            // Wajib milik produk baris ini -- mencegah baris "salah
            // tempel" variasi produk lain (beda dari member_id/table_id
            // yang boleh kosong; variasi bukan sesuatu yang bisa diketik
            // bebas, harus berasal dari katalog produk itu sendiri).
            $variation = ProductVariation::with(['components.item', 'components.uom'])
                ->where('product_id', $product->id)
                ->findOrFail($variationData['variation_id']);

            $nameSnapshot = trim((string) ($variationData['name'] ?? '')) !== ''
                ? $variationData['name']
                : $variation->name;

            $priceSnapshot = array_key_exists('price', $variationData) && $variationData['price'] !== null
                ? (string) $variationData['price']
                : (string) $variation->additional_price;

            $variationHpp = '0';

            foreach ($variation->components as $component) {
                $componentQty = bcmul((string) $component->qty, $qty, self::SCALE);
                $componentQtyInBaseUom = $this->inventory->convertToItemBaseUom($component->item, $component->uom, $componentQty);

                $hpp = $this->inventory->recordOutbound(
                    $component->item,
                    $warehouse,
                    $componentQtyInBaseUom,
                    $sale,
                    $date,
                );

                $variationHpp = bcadd($variationHpp, $hpp, self::SCALE);
            }

            $rows[] = [
                'variation_id' => $variation->id,
                'name_snapshot' => $nameSnapshot,
                'price_snapshot' => $priceSnapshot,
                'hpp_snapshot' => $variationHpp,
            ];
            $totalHpp = bcadd($totalHpp, $variationHpp, self::SCALE);
        }

        return [$rows, $totalHpp];
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
