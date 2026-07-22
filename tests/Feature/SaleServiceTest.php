<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private SaleService $sales;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Uom $pcs;

    private Uom $gr;

    private Uom $ml;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        // FoundationSeeder default (produksi): ppn_active=false (toko
        // belum PKP). Test class ini secara khusus menguji rumus
        // penguraian PPN, jadi butuh saklar aktif sebagai baseline —
        // di-set eksplisit di sini, bukan mengandalkan default seed.
        // Satu test (switch OFF) meng-override ini balik ke false sendiri.
        CompanySetting::current()->update(['ppn_active' => true]);

        $this->inventory = new InventoryService();
        $this->sales = new SaleService($this->inventory, new PostingService(), new CashAccountService());

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();

        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->gr = Uom::where('code', 'GR')->firstOrFail();
        $this->ml = Uom::where('code', 'ML')->firstOrFail();

        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    protected function tearDown(): void
    {
        // Beberapa test di bawah membekukan "sekarang" lewat
        // Carbon::setTestNow() untuk menguji jam rawan lintas tengah malam
        // UTC -- WAJIB direset di sini, bukan cuma di akhir masing-masing
        // test, karena kalau assertion gagal di tengah jalan, reset di
        // akhir method tidak akan pernah tereksekusi dan waktu beku itu
        // akan bocor ke test class lain yang jalan sesudahnya.
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Bug A: `Kasir\SaleController::store()` mengirim `now()` sebagai
     * `date`. Sebelum perbaikan timezone, `now()` mengembalikan UTC mentah
     * -- transaksi yang terjadi WIB 00:00-06:59 (= UTC hari SEBELUMNYA)
     * tercatat mundur sehari. Test ini membekukan waktu TEPAT di jam rawan
     * itu (WIB 01:00, yang mana UTC MASIH tanggal sebelumnya) dan
     * membuktikan sales.date mengikuti kalender WIB, bukan UTC.
     */
    public function test_sale_created_at_dawn_wib_is_dated_the_correct_wib_day_not_the_prior_utc_day(): void
    {
        // WIB 2026-07-19 01:00 == UTC 2026-07-18 18:00 -- kalau bug lama
        // masih ada, sales.date akan jadi 2026-07-18 (SALAH).
        Carbon::setTestNow(Carbon::create(2026, 7, 19, 1, 0, 0, 'Asia/Jakarta'));
        $this->assertSame('2026-07-18', now('UTC')->toDateString(), 'Prasyarat test: UTC harus masih tanggal 18 di titik waktu beku ini.');

        [$item, $product] = $this->makeWidgetProductForTimezoneTests();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now(), // persis seperti Kasir\SaleController::store()
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ]);

        $this->assertSame('2026-07-19', $sale->date->toDateString(), 'sales.date harus tanggal WIB (19), bukan tanggal UTC (18).');

        // Turunan lain dari tanggal yang sama harus ikut benar: jurnal &
        // stock_movement dipetakan dari $occurredAt yang sama, bukan
        // $data['date'] mentah -- membuktikan perbaikan konsisten, bukan
        // cuma di kolom sales.date saja.
        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $this->assertSame('2026-07-19', $journal->date->toDateString());

        $movement = StockMovement::where('item_id', $item->id)->where('source_type', Sale::class)->firstOrFail();
        $this->assertSame('2026-07-19', $movement->date->toDateString());
    }

    /**
     * occurred_at dari HP (mengirim UTC eksplisit ber-'Z', perilaku BARU
     * setelah perbaikan mobile) harus tersimpan sebagai jam WIB yang
     * benar, bukan meleset 7 jam. Momen sungguhan: WIB 19 Juli 14:30 = UTC
     * 19 Juli 07:30 -- HP (setelah diperbaiki) mengirim string ber-akhiran
     * 'Z' persis seperti itu.
     */
    public function test_occurred_at_from_an_explicit_utc_mobile_payload_is_stored_as_the_correct_wib_clock_time(): void
    {
        [$item, $product] = $this->makeWidgetProductForTimezoneTests();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-19T07:30:00.000Z',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ]);

        $this->assertNotNull($sale->occurred_at);
        // Jam WIB yang benar (14:30) -- BUKAN 07:30 (kalau UTC mentah tidak
        // dikonversi) dan BUKAN 21:30/lainnya (kalau konversi arahnya kebalik).
        $this->assertSame('2026-07-19 14:30:00', $sale->occurred_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
        // Kalender WIB (date) tetap 19 -- konsisten dengan occurred_at.
        $this->assertSame('2026-07-19', $sale->date->toDateString());
    }

    /**
     * Kompatibilitas mundur: HP versi LAMA (sebelum diperbaiki) mengirim
     * string ISO TANPA offset sama sekali (kuirk `DateTime.now().
     * toIso8601String()` di Dart untuk waktu lokal) -- string ini berisi
     * digit jam WIB apa adanya. Setelah app.timezone = Asia/Jakarta, server
     * WAJIB tetap menafsirkannya benar (bukan mensyaratkan semua HP
     * ter-update dulu sebelum tanggal jadi benar).
     */
    public function test_occurred_at_from_an_old_mobile_payload_without_offset_is_still_interpreted_as_wib(): void
    {
        [$item, $product] = $this->makeWidgetProductForTimezoneTests();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-19T01:30:00.000', // tanpa offset, digit WIB apa adanya
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ]);

        $this->assertSame('2026-07-19', $sale->date->toDateString());
        $this->assertSame('2026-07-19 01:30:00', $sale->occurred_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
    }

    /**
     * @return array{0: Item, 1: Product}
     */
    private function makeWidgetProductForTimezoneTests(): array
    {
        $item = Item::create([
            'sku' => $this->uniqueCode('TZ-WIDGET'),
            'name' => 'Widget Timezone',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
        $product = Product::create(['name' => 'Widget Timezone Product', 'sell_price' => 5000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        return [$item, $product];
    }

    public function test_full_coffee_sale_deducts_stock_computes_hpp_and_posts_a_balanced_journal(): void
    {
        $kopi = $this->makeStockedItem('KOPI-SACHET', 'Kopi Sachet', $this->pcs);
        $gula = $this->makeStockedItem('GULA', 'Gula', $this->gr);
        $gelas = $this->makeStockedItem('GELAS', 'Gelas', $this->pcs);
        $air = $this->makeCostOnlyItem('AIR', 'Air', $this->ml, '200');

        $openingBalanceSource = $this->makeOpeningBalanceSource();
        $this->inventory->recordInbound($kopi, $this->warehouse, 100, 1500, $openingBalanceSource, '2026-07-01');
        $this->inventory->recordInbound($gula, $this->warehouse, 1000, 20, $openingBalanceSource, '2026-07-01');
        $this->inventory->recordInbound($gelas, $this->warehouse, 50, 500, $openingBalanceSource, '2026-07-01');

        $product = $this->makeCoffeeProduct($kopi, $gula, $gelas, $air);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 15000],
            ],
        ]);

        // (a) stok komponen berkurang sesuai BOM x qty jual.
        $this->assertSame('98.0000', $this->inventory->currentStock($kopi, $this->warehouse));
        $this->assertSame('970.0000', $this->inventory->currentStock($gula, $this->warehouse));
        $this->assertSame('48.0000', $this->inventory->currentStock($gelas, $this->warehouse));

        // (b) item cost_only (air) tidak pernah punya stock_movement.
        $this->assertSame(0, StockMovement::where('item_id', $air->id)->count());

        // (c) hpp_total sale_line = jumlah HPP semua komponen (termasuk air).
        // kopi: 2 x 1500 = 3000 | gula: 30 x 20 = 600 | gelas: 2 x 500 = 1000 | air: 2 x 200 = 400
        $saleLine = $sale->lines->first();
        $this->assertSame(0, bccomp($saleLine->hpp_total, '5000', 4));

        // Harga tax-inclusive: unit_price 15000 x qty 2 = 30000 adalah harga
        // YANG DIBAYAR (grand_total), PPN 11% diurai dari dalamnya, bukan
        // ditambah di atasnya. net = 30000 ÷ 1.11 (truncate skala 4).
        $this->assertSame(0, bccomp($sale->grand_total, '30000', 4));
        $this->assertSame(0, bccomp($sale->subtotal, '27027.0270', 4));
        $this->assertSame(0, bccomp($sale->tax_total, '2972.9730', 4));
        // net + tax harus eksak sama dengan grand_total, tanpa residu.
        $this->assertSame(0, bccomp(bcadd($sale->subtotal, $sale->tax_total, 4), $sale->grand_total, 4));

        // (d) jurnal seimbang dan akun-akunnya benar.
        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1000']->debit, '30000', 4));
        $this->assertSame(0, bccomp($lines['1-1000']->credit, '0', 4));

        $this->assertSame(0, bccomp($lines['4-1000']->credit, '27027.0270', 4));
        $this->assertSame(0, bccomp($lines['2-1100']->credit, '2972.9730', 4));

        $this->assertSame(0, bccomp($lines['5-1000']->debit, '5000', 4));
        $this->assertSame(0, bccomp($lines['1-1200']->credit, '5000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    public function test_a_failing_bom_component_rolls_back_the_entire_sale(): void
    {
        $kopi = $this->makeStockedItem('KOPI-SACHET', 'Kopi Sachet', $this->pcs);
        $gelas = $this->makeStockedItem('GELAS', 'Gelas', $this->pcs);

        $openingBalanceSource = $this->makeOpeningBalanceSource();
        $this->inventory->recordInbound($gelas, $this->warehouse, 50, 500, $openingBalanceSource, '2026-07-01');
        $this->inventory->recordInbound($kopi, $this->warehouse, 100, 1500, $openingBalanceSource, '2026-07-01');

        $stockMovementCountBefore = StockMovement::count();

        $product = Product::create(['name' => 'Kopi Gagal', 'sell_price' => 15000]);

        // Komponen pertama valid dan akan berhasil diproses (menulis stock_movement)
        // sebelum komponen kedua gagal karena UOM-nya tidak punya jalur konversi
        // ke base_uom item (ML -> PCS tidak didefinisikan) — membuktikan langkah
        // yang sudah "berhasil" pun ikut rollback.
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $kopi->id, 'qty' => 1, 'uom_id' => $this->ml->id]);

        try {
            $this->sales->createSale([
                'outlet_id' => $this->outlet->id,
                'warehouse_id' => $this->warehouse->id,
                'date' => '2026-07-04',
                'payment_method' => 'cash',
                'lines' => [
                    ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 15000],
                ],
            ]);

            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SaleLine::count());
        $this->assertSame(0, Journal::count());
        $this->assertSame(0, JournalLine::count());
        // Tidak ada stock_movement baru (termasuk yang sempat ditulis untuk gelas).
        $this->assertSame($stockMovementCountBefore, StockMovement::count());
        $this->assertSame('50.0000', $this->inventory->currentStock($gelas, $this->warehouse));
    }

    public function test_creating_a_sale_with_an_existing_local_uuid_returns_the_existing_sale_without_reprocessing(): void
    {
        $item = $this->makeStockedItem('WIDGET', 'Widget', $this->pcs);
        $openingBalanceSource = $this->makeOpeningBalanceSource();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $openingBalanceSource, '2026-07-01');

        $product = Product::create(['name' => 'Widget Product', 'sell_price' => 5000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        $localUuid = (string) Str::uuid();
        $saleData = [
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'local_uuid' => $localUuid,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ];

        $first = $this->sales->createSale($saleData);

        $this->assertSame('98.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame(1, Sale::count());

        // Retry dengan local_uuid yang sama (skenario HP kasir retry karena koneksi
        // putus) harus mengembalikan Sale yang sama tanpa memotong stok atau
        // posting jurnal lagi.
        $second = $this->sales->createSale($saleData);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SaleLine::count());
        $this->assertSame(1, Journal::where('source_type', Sale::class)->where('source_id', $first->id)->count());
        $this->assertSame('98.0000', $this->inventory->currentStock($item, $this->warehouse));
    }

    // --- PPN tax-inclusive: saklar global x tarif per produk (3 kasus + keranjang campuran) ---
    // Angka acuan: harga inclusive Rp8.000, tarif 11%.
    // net = bcdiv(8000, 1.11, 4) = 7207.2072 | tax = bcsub(8000, 7207.2072, 4) = 792.7928.

    public function test_sale_defaults_to_kas_when_cash_account_code_is_not_provided(): void
    {
        $product = Product::create(['name' => 'Produk Tanpa Pajak', 'sell_price' => 5000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ]);

        $this->assertSame('1-1000', $sale->cash_account_code);
    }

    public function test_sale_with_bank_selected_credits_bank_instead_of_kas(): void
    {
        $product = Product::create(['name' => 'Produk Tanpa Pajak', 'sell_price' => 5000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'cash_account_code' => '1-1100',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ]);

        $this->assertSame('1-1100', $sale->cash_account_code);

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1100']->debit, '5000', 4));
        $this->assertFalse($lines->has('1-1000'));
    }

    public function test_ppn_switch_off_produces_no_tax_even_for_a_taxable_product(): void
    {
        CompanySetting::current()->update(['ppn_active' => false]);

        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
        $product = Product::create(['name' => 'Produk Kena Pajak', 'sell_price' => 8000, 'tax_rate_id' => $taxRate->id]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]],
        ]);

        $this->assertSame(0, bccomp($sale->subtotal, '8000', 4));
        $this->assertSame(0, bccomp($sale->tax_total, '0', 4));
        $this->assertSame(0, bccomp($sale->grand_total, '8000', 4));

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1000']->debit, '8000', 4));
        $this->assertSame(0, bccomp($lines['4-1000']->credit, '8000', 4));
        $this->assertArrayNotHasKey('2-1100', $lines->all(), 'Tidak boleh ada baris PPN Keluaran saat saklar off.');
        $this->assertJournalBalanced($journal);
    }

    public function test_ppn_switch_on_and_taxable_product_extracts_tax_from_the_inclusive_price(): void
    {
        // Saklar sudah di-set true di setUp() — dites eksplisit di sini.
        $this->assertTrue(CompanySetting::current()->ppn_active);

        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
        $product = Product::create(['name' => 'Produk Kena Pajak', 'sell_price' => 8000, 'tax_rate_id' => $taxRate->id]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]],
        ]);

        $this->assertSame(0, bccomp($sale->subtotal, '7207.2072', 4));
        $this->assertSame(0, bccomp($sale->tax_total, '792.7928', 4));
        $this->assertSame(0, bccomp($sale->grand_total, '8000', 4));
        // net + tax harus eksak sama dengan grand_total (harga tampil), tanpa residu.
        $this->assertSame(0, bccomp(bcadd($sale->subtotal, $sale->tax_total, 4), $sale->grand_total, 4));

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1000']->debit, '8000', 4));
        $this->assertSame(0, bccomp($lines['4-1000']->credit, '7207.2072', 4));
        $this->assertSame(0, bccomp($lines['2-1100']->credit, '792.7928', 4));
        $this->assertJournalBalanced($journal);
    }

    public function test_ppn_switch_on_but_untaxed_product_has_no_tax(): void
    {
        $this->assertTrue(CompanySetting::current()->ppn_active);

        // Tidak ada tax_rate_id sama sekali — produk ini memang tidak kena PPN.
        $product = Product::create(['name' => 'Produk Tanpa Pajak', 'sell_price' => 8000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 8000]],
        ]);

        $this->assertSame(0, bccomp($sale->subtotal, '8000', 4));
        $this->assertSame(0, bccomp($sale->tax_total, '0', 4));
        $this->assertSame(0, bccomp($sale->grand_total, '8000', 4));

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1000']->debit, '8000', 4));
        $this->assertSame(0, bccomp($lines['4-1000']->credit, '8000', 4));
        $this->assertArrayNotHasKey('2-1100', $lines->all(), 'Produk tanpa tax_rate_id tidak boleh memicu PPN Keluaran meski saklar on.');
        $this->assertJournalBalanced($journal);
    }

    public function test_mixed_cart_taxes_only_the_taxable_line(): void
    {
        $this->assertTrue(CompanySetting::current()->ppn_active);

        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
        $taxed = Product::create(['name' => 'Kena Pajak', 'sell_price' => 8000, 'tax_rate_id' => $taxRate->id]);
        $untaxed = Product::create(['name' => 'Tanpa Pajak', 'sell_price' => 8000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [
                ['product_id' => $taxed->id, 'qty' => 1, 'unit_price' => 8000],
                ['product_id' => $untaxed->id, 'qty' => 1, 'unit_price' => 8000],
            ],
        ]);

        // 7207.2072 (net baris kena pajak) + 8000 (baris tanpa pajak) = 15207.2072
        $this->assertSame(0, bccomp($sale->subtotal, '15207.2072', 4));
        $this->assertSame(0, bccomp($sale->tax_total, '792.7928', 4));
        $this->assertSame(0, bccomp($sale->grand_total, '16000', 4));
        $this->assertSame(0, bccomp(bcadd($sale->subtotal, $sale->tax_total, 4), $sale->grand_total, 4));

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['1-1000']->debit, '16000', 4));
        $this->assertSame(0, bccomp($lines['4-1000']->credit, '15207.2072', 4));
        $this->assertSame(0, bccomp($lines['2-1100']->credit, '792.7928', 4));
        $this->assertJournalBalanced($journal);
    }

    /**
     * unit_price/line_total sudah snapshot sejak awal (disimpan langsung,
     * tidak pernah dihitung ulang dari relasi) — product_name yang
     * ketinggalan. Test ini membuktikan nama produk ikut dibekukan di
     * sale_lines.product_name persis seperti nama produk saat transaksi.
     */
    public function test_sale_line_stores_a_product_name_snapshot_at_creation_time(): void
    {
        $product = Product::create(['name' => 'Kopi Susu', 'sell_price' => 15000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 15000]],
        ]);

        $this->assertSame('Kopi Susu', $sale->lines->first()->product_name);
    }

    /**
     * Inti dari bug yang diperbaiki: rename produk SETELAH transaksi tidak
     * boleh mengubah nama yang sudah dibekukan di baris penjualan lama.
     */
    public function test_renaming_a_product_after_a_sale_does_not_change_the_stored_snapshot(): void
    {
        $product = Product::create(['name' => 'Nama Lama', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $product->update(['name' => 'Nama Baru']);

        $this->assertSame('Nama Lama', $sale->lines->first()->fresh()->product_name);
        $this->assertSame('Nama Baru', $product->fresh()->name);
    }

    /**
     * Klien yang sudah tahu nama produk pada momen transaksi SUNGGUHAN
     * (mis. mobile offline yang mengirim productNameSnapshot lokalnya
     * sendiri saat akhirnya sync) harus dipercaya apa adanya, bukan ditimpa
     * oleh lookup nama produk saat ini di server — satu-satunya cara benar
     * menangani kasus produk di-rename SELAMA device kasir sedang offline.
     */
    public function test_caller_supplied_product_name_overrides_the_live_lookup(): void
    {
        $product = Product::create(['name' => 'Nama Sekarang Di Server', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 10000,
                'product_name' => 'Nama Saat Transaksi Offline',
            ]],
        ]);

        $this->assertSame('Nama Saat Transaksi Offline', $sale->lines->first()->product_name);
    }

    /**
     * Baris LAMA (sebelum kolom ini ada) dibekukan lewat backfill satu-kali
     * di migrasi, bukan dibiarkan NULL selamanya — inilah statement backfill
     * itu, dites ulang secara terisolasi (migrasi sendiri sudah jalan tanpa
     * baris NULL apa pun saat RefreshDatabase memuat skema kosong).
     */
    public function test_backfilling_legacy_null_product_name_rows_uses_the_current_product_name(): void
    {
        $product = Product::create(['name' => 'Kopi Original', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);
        $line = $sale->lines->first();

        // Simulasikan baris dari SEBELUM kolom ini ada.
        \Illuminate\Support\Facades\DB::table('sale_lines')->where('id', $line->id)->update(['product_name' => null]);
        $product->update(['name' => 'Kopi Rename Setelah Baris Lama Ada']);

        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            UPDATE sale_lines
            JOIN products ON products.id = sale_lines.product_id
            SET sale_lines.product_name = products.name
            WHERE sale_lines.product_name IS NULL
        SQL);

        $this->assertSame('Kopi Rename Setelah Baris Lama Ada', $line->fresh()->product_name);
    }

    private function assertJournalBalanced(Journal $journal): void
    {
        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));
    }

    private function makeCoffeeProduct(Item $kopi, Item $gula, Item $gelas, Item $air): Product
    {
        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();

        $product = Product::create([
            'name' => 'Kopi Seduh',
            'sell_price' => 15000,
            'tax_rate_id' => $taxRate->id,
        ]);

        ProductComponent::create(['product_id' => $product->id, 'item_id' => $kopi->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $gula->id, 'qty' => 15, 'uom_id' => $this->gr->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $air->id, 'qty' => 1, 'uom_id' => $this->ml->id]);

        return $product;
    }

    private function makeStockedItem(string $sku, string $name, Uom $baseUom): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode($sku),
            'name' => $name,
            'costing_type' => 'stocked',
            'base_uom_id' => $baseUom->id,
            'purchase_uom_id' => $baseUom->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function makeCostOnlyItem(string $sku, string $name, Uom $baseUom, string $standardCost): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode($sku),
            'name' => $name,
            'costing_type' => 'cost_only',
            'base_uom_id' => $baseUom->id,
            'purchase_uom_id' => $baseUom->id,
            'standard_cost' => $standardCost,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function makeOpeningBalanceSource(): Outlet
    {
        return Outlet::create(['name' => 'Opening Balance '.(++self::$seq)]);
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix.'-'.(++self::$seq);
    }
}
