<?php

namespace Tests\Feature;

use App\Exceptions\SaleAlreadyVoidedException;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\CashAccountService;
use App\Services\DraftSyncService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SaleServiceVoidTest extends TestCase
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
        CompanySetting::current()->update(['ppn_active' => true]);

        $this->inventory = new InventoryService();
        $this->sales = new SaleService($this->inventory, new PostingService(), new CashAccountService(), new DraftSyncService(new BranchService()));

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();

        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->gr = Uom::where('code', 'GR')->firstOrFail();
        $this->ml = Uom::where('code', 'ML')->firstOrFail();

        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Mirror lengkap `SaleServiceTest::test_full_coffee_sale_...` (kopi,
     * gula, gelas -- semua stocked; air -- cost_only), lalu void-kan
     * hasilnya. Membuktikan efek NETO (jurnal asli + jurnal pembalik)
     * nol di setiap akun yang tersentuh, dan stok kembali persis ke
     * saldo pembuka -- bukti "seolah transaksi ini tidak pernah terjadi".
     */
    public function test_void_reverses_journal_and_stock_back_to_pre_sale_state(): void
    {
        [$kopi, $gula, $gelas, $air, $product] = $this->makeCoffeeSaleFixtures();

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 15000],
            ],
        ]);

        $this->assertSame('98.0000', $this->inventory->currentStock($kopi, $this->warehouse));
        $this->assertSame('970.0000', $this->inventory->currentStock($gula, $this->warehouse));
        $this->assertSame('48.0000', $this->inventory->currentStock($gelas, $this->warehouse));

        $voidedBy = User::factory()->create();
        $voided = $this->sales->voidSale($sale->fresh(), 'Salah input produk', $voidedBy->id);

        $this->assertSame('void', $voided->status);

        // Stok kembali PERSIS ke saldo pembuka -- baris pembalik menambah
        // balik qty yang keluar, di unit_cost yang SAMA.
        $this->assertSame('100.0000', $this->inventory->currentStock($kopi, $this->warehouse));
        $this->assertSame('1000.0000', $this->inventory->currentStock($gula, $this->warehouse));
        $this->assertSame('50.0000', $this->inventory->currentStock($gelas, $this->warehouse));
        // Item cost_only tidak pernah punya stock_movement sama sekali --
        // tidak ada apa pun untuk dibalik untuk dia.
        $this->assertSame(0, StockMovement::where('item_id', $air->id)->count());

        // Efek NETO (SEMUA jurnal milik sale + void-nya) nol di setiap akun.
        $saleJournalIds = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->pluck('id');
        $voidJournalIds = Journal::where('source_type', SaleVoid::class)->pluck('id');
        $allLines = JournalLine::whereIn('journal_id', $saleJournalIds->merge($voidJournalIds))
            ->with('account')
            ->get()
            ->groupBy(fn (JournalLine $line) => $line->account->code);

        foreach (['1-1000', '4-1000', '2-1100', '5-1000', '1-1200'] as $code) {
            $net = $allLines->get($code, collect())->reduce(
                fn (string $carry, JournalLine $line) => bcadd($carry, bcsub($line->debit, $line->credit, 4), 4),
                '0',
            );
            $this->assertSame(0, bccomp($net, '0', 4), "Akun {$code} harus net nol setelah void, dapat {$net}.");
        }
    }

    /**
     * Jurnal pembalik tanggalnya sama dengan SALE ASLI (supaya laporan
     * periode transaksi asli juga menunjukkan efek nol) -- BEDA dari
     * stock_movements pembalik yang tanggalnya SAAT VOID (stok saat ini
     * itu konsep maju-ke-depan). Lihat docblock `SaleService::voidSale()`.
     */
    public function test_void_journal_dated_original_sale_date_but_stock_movement_dated_today(): void
    {
        [, , , , $product] = $this->makeCoffeeSaleFixtures();

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'payment_method' => 'cash',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 15000]],
        ]);

        Carbon::setTestNow(Carbon::create(2026, 9, 23, 10, 0, 0, 'Asia/Jakarta'));
        $this->sales->voidSale($sale->fresh(), 'Uji tanggal', null);

        $reversalJournal = Journal::where('source_type', SaleVoid::class)->firstOrFail();
        $this->assertSame('2026-07-04', $reversalJournal->date->toDateString());

        $reversalMovement = StockMovement::where('source_type', SaleVoid::class)->firstOrFail();
        $this->assertSame('2026-09-23', $reversalMovement->date->toDateString());
    }

    public function test_voiding_an_already_voided_sale_throws(): void
    {
        [, , , , $product] = $this->makeCoffeeSaleFixtures();

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 15000]],
        ]);

        $this->sales->voidSale($sale->fresh(), 'Pertama', null);

        $this->expectException(SaleAlreadyVoidedException::class);
        $this->sales->voidSale($sale->fresh(), 'Kedua', null);
    }

    public function test_void_records_an_audit_row_with_reason_and_voider(): void
    {
        [, , , , $product] = $this->makeCoffeeSaleFixtures();

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 15000]],
        ]);

        $admin = User::factory()->create();
        $this->sales->voidSale($sale->fresh(), 'Pelanggan batal', $admin->id);

        $saleVoid = SaleVoid::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('Pelanggan batal', $saleVoid->reason);
        $this->assertSame($admin->id, $saleVoid->voided_by_user_id);
    }

    /**
     * @return array{0: Item, 1: Item, 2: Item, 3: Item, 4: Product} [kopi, gula, gelas, air, product]
     */
    private function makeCoffeeSaleFixtures(): array
    {
        $kopi = $this->makeStockedItem('KOPI-SACHET', 'Kopi Sachet', $this->pcs);
        $gula = $this->makeStockedItem('GULA', 'Gula', $this->gr);
        $gelas = $this->makeStockedItem('GELAS', 'Gelas', $this->pcs);
        $air = $this->makeCostOnlyItem('AIR', 'Air', $this->ml, '200');

        $openingBalanceSource = $this->makeOpeningBalanceSource();
        $this->inventory->recordInbound($kopi, $this->warehouse, 100, 1500, $openingBalanceSource, '2026-07-01');
        $this->inventory->recordInbound($gula, $this->warehouse, 1000, 20, $openingBalanceSource, '2026-07-01');
        $this->inventory->recordInbound($gelas, $this->warehouse, 50, 500, $openingBalanceSource, '2026-07-01');

        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
        $product = Product::create(['name' => 'Kopi Seduh', 'sell_price' => 15000, 'tax_rate_id' => $taxRate->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $kopi->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $gula->id, 'qty' => 15, 'uom_id' => $this->gr->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $gelas->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $air->id, 'qty' => 1, 'uom_id' => $this->ml->id]);

        return [$kopi, $gula, $gelas, $air, $product];
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
