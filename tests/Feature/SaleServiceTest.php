<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Member;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductVariation;
use App\Models\ProductVariationComponent;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\DraftSyncService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\ProductProfitReportService;
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
        $this->sales = new SaleService($this->inventory, new PostingService(), new CashAccountService(), new DraftSyncService());

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

    /**
     * Sale tanpa member sama sekali (kasir tidak mengisi apa pun, atau
     * fitur member nonaktif) -- member_id dan member_name_snapshot harus
     * dua-duanya null, supaya baris "Pelanggan: ..." di struk bisa
     * dilewati sepenuhnya (lihat Penjualan/Receipt.jsx & Show.jsx).
     */
    public function test_sale_without_member_data_has_null_member_id_and_snapshot(): void
    {
        $product = Product::create(['name' => 'Produk Tanpa Member', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertNull($sale->member_id);
        $this->assertNull($sale->member_name_snapshot);
    }

    /**
     * Kasir memilih member dari daftar (member_id dikirim, member_name
     * tidak) -- kasus real-time web Kasir, snapshot diambil dari nama
     * Member SAAT INI karena tidak ada jeda waktu antara pilih dan simpan.
     */
    public function test_sale_with_member_id_snapshots_the_members_current_name(): void
    {
        $member = Member::create(['name' => 'Budi Santoso']);
        $product = Product::create(['name' => 'Produk Member', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'member_id' => $member->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertSame($member->id, $sale->member_id);
        $this->assertSame('Budi Santoso', $sale->member_name_snapshot);
    }

    /**
     * Inti dari disiplin snapshot: rename member SETELAH transaksi tidak
     * boleh mengubah nama yang sudah dibekukan di sales.member_name_snapshot
     * -- struk lama harus tetap menampilkan nama saat transaksi terjadi.
     */
    public function test_renaming_a_member_after_a_sale_does_not_change_the_stored_snapshot(): void
    {
        $member = Member::create(['name' => 'Nama Lama']);
        $product = Product::create(['name' => 'Produk Member', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'member_id' => $member->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $member->update(['name' => 'Nama Baru']);

        $this->assertSame('Nama Lama', $sale->fresh()->member_name_snapshot);
        $this->assertSame($member->id, $sale->fresh()->member_id);
        $this->assertSame('Nama Baru', $member->fresh()->name);
    }

    /**
     * Kasus offline mobile: member_name dikirim eksplisit (nama pada momen
     * transaksi sungguhan, disimpan lokal sebelum sync) -- ini SELALU
     * menang atas lookup nama Member saat ini, persis pola product_name.
     */
    public function test_caller_supplied_member_name_overrides_the_live_lookup(): void
    {
        $member = Member::create(['name' => 'Nama Sekarang Di Server']);
        $product = Product::create(['name' => 'Produk Member', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'member_id' => $member->id,
            'member_name' => 'Nama Saat Transaksi Offline',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertSame('Nama Saat Transaksi Offline', $sale->member_name_snapshot);
        $this->assertSame($member->id, $sale->member_id);
    }

    /**
     * Pelanggan walk-in: kasir mengetik nama bebas TANPA memilih dari
     * daftar Member -- valid, member_id tetap null (tidak ada Member row
     * untuk dikaitkan), tapi nama tetap tersimpan sebagai snapshot dan
     * tetap tampil di struk.
     */
    public function test_free_typed_member_name_without_a_member_id_is_a_valid_walkin_customer(): void
    {
        $product = Product::create(['name' => 'Produk Walk-in', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'member_name' => 'Pelanggan Walk-in',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertNull($sale->member_id);
        $this->assertSame('Pelanggan Walk-in', $sale->member_name_snapshot);
    }

    /**
     * Sale tanpa meja sama sekali (kasir tidak mengisi apa pun, atau fitur
     * meja nonaktif) -- table_id dan table_name_snapshot harus dua-duanya
     * null, supaya baris "Meja: ..." di struk bisa dilewati sepenuhnya
     * (lihat Penjualan/Receipt.jsx & Show.jsx).
     */
    public function test_sale_without_table_data_has_null_table_id_and_snapshot(): void
    {
        $product = Product::create(['name' => 'Produk Tanpa Meja', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertNull($sale->table_id);
        $this->assertNull($sale->table_name_snapshot);
    }

    /**
     * Kasir memilih meja dari daftar (table_id dikirim, table_name tidak)
     * -- kasus real-time web Kasir, snapshot diambil dari nama meja SAAT
     * INI karena tidak ada jeda waktu antara pilih dan simpan.
     */
    public function test_sale_with_table_id_snapshots_the_tables_current_name(): void
    {
        $table = DiningTable::create(['name' => 'Meja 5']);
        $product = Product::create(['name' => 'Produk Meja', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'table_id' => $table->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertSame($table->id, $sale->table_id);
        $this->assertSame('Meja 5', $sale->table_name_snapshot);
    }

    /**
     * Inti dari disiplin snapshot: rename meja SETELAH transaksi tidak
     * boleh mengubah nama yang sudah dibekukan di sales.table_name_snapshot
     * -- struk lama harus tetap menampilkan nama saat transaksi terjadi.
     */
    public function test_renaming_a_table_after_a_sale_does_not_change_the_stored_snapshot(): void
    {
        $table = DiningTable::create(['name' => 'Meja Lama']);
        $product = Product::create(['name' => 'Produk Meja', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'table_id' => $table->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $table->update(['name' => 'Meja Baru']);

        $this->assertSame('Meja Lama', $sale->fresh()->table_name_snapshot);
        $this->assertSame($table->id, $sale->fresh()->table_id);
        $this->assertSame('Meja Baru', $table->fresh()->name);
    }

    /**
     * Kasus offline mobile: table_name dikirim eksplisit (nama pada momen
     * transaksi sungguhan, disimpan lokal sebelum sync) -- ini SELALU
     * menang atas lookup nama meja saat ini, persis pola member_name.
     */
    public function test_caller_supplied_table_name_overrides_the_live_lookup(): void
    {
        $table = DiningTable::create(['name' => 'Nama Sekarang Di Server']);
        $product = Product::create(['name' => 'Produk Meja', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'table_id' => $table->id,
            'table_name' => 'Nama Saat Transaksi Offline',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertSame('Nama Saat Transaksi Offline', $sale->table_name_snapshot);
        $this->assertSame($table->id, $sale->table_id);
    }

    /**
     * Meja belum terdaftar: kasir mengetik nomor meja bebas TANPA memilih
     * dari daftar DiningTable -- valid, table_id tetap null, tapi nama
     * tetap tersimpan sebagai snapshot dan tetap tampil di struk.
     */
    public function test_free_typed_table_name_without_a_table_id_is_valid(): void
    {
        $product = Product::create(['name' => 'Produk Meja Bebas', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'table_name' => 'Meja Tambahan Di Luar',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertNull($sale->table_id);
        $this->assertSame('Meja Tambahan Di Luar', $sale->table_name_snapshot);
    }

    /**
     * Sale tanpa catatan sama sekali (kasir tidak mengisi apa pun, atau
     * fitur catatan nonaktif) -- note (sale) dan note (sale_line) harus
     * null, supaya baris "Catatan"/"→ ..." di struk bisa dilewati
     * sepenuhnya (lihat Penjualan/Receipt.jsx & Show.jsx).
     */
    public function test_sale_without_note_has_null_note_on_sale_and_line(): void
    {
        $product = Product::create(['name' => 'Produk Tanpa Catatan', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertNull($sale->note);
        $this->assertNull($sale->lines->first()->note);
    }

    /**
     * Catatan per-transaksi & per-item disimpan APA ADANYA -- beda dari
     * member/table, tidak ada resolusi/lookup apa pun di sini (lihat
     * docblock SaleService::createSale()).
     */
    public function test_sale_stores_per_sale_and_per_line_notes_as_given(): void
    {
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'note' => 'Antar ke meja 5',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 5000,
                'note' => 'Es sedikit, jangan manis',
            ]],
        ]);

        $this->assertSame('Antar ke meja 5', $sale->note);
        $this->assertSame('Es sedikit, jangan manis', $sale->lines->first()->note);
    }

    /**
     * Catatan kosong (string kosong/spasi) diperlakukan sama seperti tidak
     * diisi sama sekali -- diratakan jadi NULL, bukan disimpan sebagai
     * string kosong, supaya pemeriksaan "kalau terisi" di sisi tampilan
     * (Receipt.jsx/Show.jsx/ReceiptFormatter) selalu bisa memakai
     * null-check sederhana.
     */
    public function test_blank_notes_are_normalized_to_null(): void
    {
        $product = Product::create(['name' => 'Produk Catatan Kosong', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'note' => '   ',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000, 'note' => '']],
        ]);

        $this->assertNull($sale->note);
        $this->assertNull($sale->lines->first()->note);
    }

    /**
     * Rename produk atau meja tidak memengaruhi catatan -- catatan berdiri
     * sendiri, tidak terkait entitas manapun (beda dari product_name/
     * member_name/table_name yang memang snapshot dari entitas lain).
     * Test ini murni memastikan kolom note tetap independen & utuh di
     * tengah operasi lain pada sale yang sama.
     */
    public function test_note_survives_alongside_other_snapshot_fields(): void
    {
        $table = DiningTable::create(['name' => 'Meja 3']);
        $product = Product::create(['name' => 'Produk Campuran', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'table_id' => $table->id,
            'note' => 'Bungkus',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000, 'note' => 'Extra pedas']],
        ]);

        $table->update(['name' => 'Meja 3 Direnovasi']);

        $this->assertSame('Bungkus', $sale->fresh()->note);
        $this->assertSame('Extra pedas', $sale->lines->first()->fresh()->note);
        $this->assertSame('Meja 3', $sale->fresh()->table_name_snapshot);
    }

    /**
     * Baris tanpa `variations` sama sekali (produk tidak punya variasi,
     * atau fitur nonaktif) -- sale_line_variations tetap kosong, tidak ada
     * baris "kosong" apa pun yang dibuat.
     */
    public function test_sale_line_without_variations_has_no_variation_rows(): void
    {
        $product = Product::create(['name' => 'Kopi Tanpa Variasi', 'sell_price' => 10000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-31',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->assertSame(0, $sale->lines->first()->variations()->count());
    }

    /**
     * Multi-variasi per baris (mis. "Es Teh + Gelas Besar + Bawa Pulang")
     * -- satu sale_line bisa punya BEBERAPA baris sale_line_variations
     * sekaligus, masing-masing dengan snapshot nama & harganya sendiri.
     * unit_price yang dikirim SUDAH termasuk kedua additional_price (7000
     * = 5000 dasar + 2000 gelas besar + 0 bawa pulang -- lihat docblock
     * SaleService::createSale() kenapa server tidak menghitung ulang ini).
     */
    public function test_sale_line_stores_multiple_variations_with_snapshots(): void
    {
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000]);
        $besar = ProductVariation::create(['product_id' => $product->id, 'name' => 'Gelas Besar', 'additional_price' => 2000]);
        $bawaPulang = ProductVariation::create(['product_id' => $product->id, 'name' => 'Bawa Pulang', 'additional_price' => 0]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-31',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 7000,
                'variations' => [
                    ['variation_id' => $besar->id],
                    ['variation_id' => $bawaPulang->id],
                ],
            ]],
        ]);

        $line = $sale->lines->first();
        $this->assertSame(2, $line->variations()->count());
        $this->assertSame(0, bccomp($line->line_total, '7000', 4), 'line_total memakai unit_price apa adanya, sudah termasuk variasi.');

        $names = $line->variations()->pluck('name_snapshot')->sort()->values()->all();
        $this->assertSame(['Bawa Pulang', 'Gelas Besar'], $names);

        $besarSnapshot = $line->variations()->where('variation_id', $besar->id)->firstOrFail();
        $this->assertSame(0, bccomp($besarSnapshot->price_snapshot, '2000', 4));
        // Tahap 1: HPP variasi SELALU 0 -- lihat docblock migrasi
        // sale_line_variations untuk bagaimana Tahap 2 mengisi ini nanti.
        $this->assertSame(0, bccomp($besarSnapshot->hpp_snapshot, '0', 4));
    }

    /**
     * Inti dari disiplin snapshot: rename ATAU ubah harga variasi SETELAH
     * transaksi tidak boleh mengubah name_snapshot/price_snapshot yang
     * sudah dibekukan -- nota lama harus tetap benar.
     */
    public function test_renaming_or_repricing_a_variation_after_a_sale_does_not_change_the_stored_snapshot(): void
    {
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000]);
        $variation = ProductVariation::create(['product_id' => $product->id, 'name' => 'Gelas Besar', 'additional_price' => 2000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-31',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 7000,
                'variations' => [['variation_id' => $variation->id]],
            ]],
        ]);

        $variation->update(['name' => 'Gelas Jumbo', 'additional_price' => 5000]);

        $snapshot = $sale->lines->first()->variations()->firstOrFail();
        $this->assertSame('Gelas Besar', $snapshot->name_snapshot);
        $this->assertSame(0, bccomp($snapshot->price_snapshot, '2000', 4));
        $this->assertSame('Gelas Jumbo', $variation->fresh()->name);
    }

    /**
     * Kasus offline mobile: name/price dikirim eksplisit (nilai pada momen
     * transaksi sungguhan, disimpan lokal sebelum sync) -- SELALU menang
     * atas lookup nilai ProductVariation saat ini, persis pola
     * member_name/table_name.
     */
    public function test_caller_supplied_variation_name_and_price_override_the_live_lookup(): void
    {
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000]);
        $variation = ProductVariation::create(['product_id' => $product->id, 'name' => 'Nama Sekarang Di Server', 'additional_price' => 2000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-31',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 6500,
                'variations' => [[
                    'variation_id' => $variation->id,
                    'name' => 'Nama Saat Transaksi Offline',
                    'price' => 1500,
                ]],
            ]],
        ]);

        $snapshot = $sale->lines->first()->variations()->firstOrFail();
        $this->assertSame('Nama Saat Transaksi Offline', $snapshot->name_snapshot);
        $this->assertSame(0, bccomp($snapshot->price_snapshot, '1500', 4));
    }

    /**
     * Variasi yang diklaim TIDAK MILIK produk baris ini harus ditolak --
     * mencegah baris "salah tempel" variasi produk lain. findOrFail() di
     * dalam createSaleLineVariations() (di-scope ke product_id) yang
     * menegakkan ini.
     */
    public function test_a_variation_belonging_to_a_different_product_is_rejected(): void
    {
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000]);
        $otherProduct = Product::create(['name' => 'Kopi', 'sell_price' => 8000]);
        $wrongVariation = ProductVariation::create(['product_id' => $otherProduct->id, 'name' => 'Extra Shot', 'additional_price' => 3000]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-31',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 8000,
                'variations' => [['variation_id' => $wrongVariation->id]],
            ]],
        ]);
    }

    /**
     * Tahap 1: variasi belum bisa mengonsumsi BOM apa pun. hpp_total pada
     * sale_line HARUS tetap murni dari komponen PRODUK saja (identik
     * sebelum fitur variasi ada) walau baris ini juga punya variasi
     * terpilih -- membuktikan loop HPP produk (createSaleLine) sama sekali
     * tidak tersentuh oleh keberadaan variasi.
     */
    public function test_hpp_total_is_unaffected_by_variations_in_tahap_1(): void
    {
        $kopi = $this->makeStockedItem('KOPI-VAR', 'Kopi Sachet', $this->pcs);
        $this->inventory->recordInbound($kopi, $this->warehouse, 100, 1500, $this->makeOpeningBalanceSource(), '2026-07-01');

        $product = Product::create(['name' => 'Kopi Seduh', 'sell_price' => 10000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $kopi->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);
        $variation = ProductVariation::create(['product_id' => $product->id, 'name' => 'Extra Shot', 'additional_price' => 3000]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-31',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 13000,
                'variations' => [['variation_id' => $variation->id]],
            ]],
        ]);

        $line = $sale->lines->first();
        // 1 unit kopi @1500 = 1500, SAMA seperti tanpa variasi sama sekali.
        $this->assertSame(0, bccomp($line->hpp_total, '1500', 4));
        $this->assertSame(0, bccomp($line->variations()->firstOrFail()->hpp_snapshot, '0', 4));
    }

    /**
     * Variasi Berbayar Tahap 2 -- rekonsiliasi dibuktikan dengan ANGKA
     * NYATA (bukan cuma "lulus"), mencakup poin (b)/(c)/(d)/(e)/(a) yang
     * diminta eksplisit. Skenario CAMPUR sengaja: satu variasi berbahan
     * (Bawa Pulang -> 1x Gelas Plastik) DAN satu variasi tanpa BOM sama
     * sekali (Ekstra Manis) dipilih SEKALIGUS di baris yang sama, produk
     * itu sendiri JUGA punya BOM sendiri (Es Batu) -- membuktikan ketiga
     * sumber HPP (produk, variasi berbahan, variasi tanpa bahan) berjalan
     * benar bersamaan, bukan hanya diuji terpisah-pisah.
     */
    public function test_variation_with_bom_deducts_stock_computes_hpp_and_reconciles_with_journal_and_profit_report(): void
    {
        $esBatu = $this->makeStockedItem('ES-BATU', 'Es Batu', $this->pcs);
        $gelasPlastik = $this->makeStockedItem('GELAS-PLASTIK', 'Gelas Plastik', $this->pcs);
        $openingBalanceSource = $this->makeOpeningBalanceSource();
        $this->inventory->recordInbound($esBatu, $this->warehouse, 100, 200, $openingBalanceSource, '2026-08-01');
        $this->inventory->recordInbound($gelasPlastik, $this->warehouse, 50, 300, $openingBalanceSource, '2026-08-01');

        // Produk PUNYA BOM sendiri (Es Batu) -- terpisah dari BOM variasi,
        // supaya hpp_total gabungan (poin d) benar-benar teruji sebagai
        // JUMLAH dua sumber berbeda, bukan kebetulan salah satunya nol.
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 8000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $esBatu->id, 'qty' => 2, 'uom_id' => $this->pcs->id]);

        $bawaPulang = ProductVariation::create(['product_id' => $product->id, 'name' => 'Bawa Pulang', 'additional_price' => 1000]);
        ProductVariationComponent::create(['variation_id' => $bawaPulang->id, 'item_id' => $gelasPlastik->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        // (e) variasi TANPA BOM sama sekali -- HPP-nya harus 0, dipilih
        // BERSAMAAN dengan variasi berbahan di baris yang sama (campur).
        $ekstraManis = ProductVariation::create(['product_id' => $product->id, 'name' => 'Ekstra Manis', 'additional_price' => 500]);

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-08-02',
            'payment_method' => 'cash',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 3,
                'unit_price' => 9500, // 8000 + 1000 + 500
                'variations' => [
                    ['variation_id' => $bawaPulang->id],
                    ['variation_id' => $ekstraManis->id],
                ],
            ]],
        ]);

        // (b) stok item bahan berkurang TEPAT sesuai BOM x qty -- komponen
        // PRODUK (es batu) dan komponen VARIASI (gelas plastik) terpisah.
        $this->assertSame(0, bccomp($this->inventory->currentStock($esBatu, $this->warehouse), '94', 4)); // 100 - (2 x 3)
        $this->assertSame(0, bccomp($this->inventory->currentStock($gelasPlastik, $this->warehouse), '47', 4)); // 50 - (1 x 3)

        $line = $sale->lines->first();
        $lineVariations = $line->variations()->get()->keyBy('variation_id');

        // (d) hpp_total = HPP produk + HPP variasi, dari Moving Average
        // yang SUDAH BENAR (bukan direcompute ulang):
        //   produk:  2 es batu/unit x 3 unit x 200 = 1200
        //   variasi Bawa Pulang: 1 gelas/unit x 3 unit x 300 = 900
        //   variasi Ekstra Manis: tanpa BOM = 0            [poin (e)]
        $this->assertSame(0, bccomp($lineVariations[$bawaPulang->id]->hpp_snapshot, '900', 4));
        $this->assertSame(0, bccomp($lineVariations[$ekstraManis->id]->hpp_snapshot, '0', 4));
        $this->assertSame(0, bccomp($line->hpp_total, '2100', 4)); // 1200 + 900 + 0

        // (c) jurnal seimbang, satu posting gabungan (tidak ada baris HPP
        // terpisah per variasi).
        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $this->assertJournalBalanced($journal);

        $journalLines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $l) => $l->account->code);
        $this->assertSame(0, bccomp($journalLines['5-1000']->debit, '2100', 4));
        $this->assertSame(0, bccomp($journalLines['1-1200']->credit, '2100', 4));
        // Produk tidak punya tax_rate_id -> tidak kena PPN sama sekali --
        // tidak ada baris 2-1100 di jurnal ini.
        $this->assertArrayNotHasKey('2-1100', $journalLines->all());
        $this->assertSame(0, bccomp($journalLines['1-1000']->debit, '28500', 4)); // 3 x 9500
        $this->assertSame(0, bccomp($journalLines['4-1000']->credit, '28500', 4));

        // (a) rekonsiliasi eksplisit dengan ANGKA NYATA yang SAMA di tiga
        // tempat berbeda: HPP laporan laba = HPP jurnal (5-1000) =
        // hpp_total tersimpan di sale_lines -- 2100 di ketiganya, bukan
        // cuma tiga assertion yang kebetulan sama-sama lulus.
        $report = (new ProductProfitReportService())->productProfitReport('2026-08-02', '2026-08-02');
        $byProduct = collect($report['by_product'])->keyBy('product_id');
        $this->assertSame(0, bccomp($byProduct[$product->id]['hpp'], '2100', 4));
        $this->assertSame(0, bccomp($byProduct[$product->id]['hpp'], (string) $line->hpp_total, 4));
        $this->assertSame(0, bccomp($byProduct[$product->id]['hpp'], (string) $journalLines['5-1000']->debit, 4));
        $this->assertSame(0, bccomp($byProduct[$product->id]['net'], '28500', 4));
        $this->assertSame(0, bccomp($byProduct[$product->id]['gross_profit'], '26400', 4)); // 28500 - 2100
    }

    /**
     * (g) Transaksi Tahap 1 LAMA (dibuat sebelum `product_variation_components`
     * ada sama sekali -- `hpp_snapshot`/`hpp_total` dibekukan sebagai '0'
     * murni angka historis) tetap valid setelah kode Tahap 2 di-deploy.
     * Dibangun langsung lewat Eloquent (BUKAN lewat SaleService), sengaja
     * TIDAK melewati consumeSaleLineVariations() sama sekali -- persis
     * merepresentasikan baris yang sudah tersimpan sebelum method itu
     * pernah ada, bukan jalur baru yang kebetulan menghasilkan nilai sama.
     */
    public function test_pre_tahap_2_sale_line_variation_rows_remain_valid_and_unrecomputed(): void
    {
        $product = Product::create(['name' => 'Kopi Legacy', 'sell_price' => 10000]);
        $variation = ProductVariation::create(['product_id' => $product->id, 'name' => 'Extra Shot Legacy', 'additional_price' => 3000]);

        $sale = Sale::create([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-01',
            'occurred_at' => '2026-07-01 10:00:00',
            'payment_method' => 'cash',
            'status' => 'completed',
            'subtotal' => '13000',
            'tax_total' => '0',
            'grand_total' => '13000',
            'cash_received' => '13000',
            'change_amount' => '0',
        ]);
        $line = $sale->lines()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'qty' => 1,
            'unit_price' => 13000,
            'line_total' => 13000,
            'hpp_total' => '0',
        ]);
        $line->variations()->create([
            'variation_id' => $variation->id,
            'name_snapshot' => $variation->name,
            'price_snapshot' => $variation->additional_price,
            'hpp_snapshot' => '0',
        ]);

        // Baris ini tetap terbaca utuh -- tidak ada kode Tahap 2 manapun
        // yang memanggil ulang consumeSaleLineVariations() terhadap data
        // yang sudah ada, jadi nilainya TIDAK PERNAH direcompute.
        $reloaded = Sale::with('lines.variations')->findOrFail($sale->id);
        $this->assertSame(0, bccomp($reloaded->lines->first()->hpp_total, '0', 4));
        $this->assertSame(0, bccomp($reloaded->lines->first()->variations->first()->hpp_snapshot, '0', 4));

        // Laporan laba tetap membaca hpp_total APA ADANYA, tanpa error.
        $report = (new ProductProfitReportService())->productProfitReport('2026-07-01', '2026-07-01');
        $byProduct = collect($report['by_product'])->keyBy('product_id');
        $this->assertSame(0, bccomp($byProduct[$product->id]['hpp'], '0', 4));
        $this->assertSame(0, bccomp($byProduct[$product->id]['net'], '13000', 4));
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
