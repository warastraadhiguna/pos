<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\CashAccountService;
use App\Services\DraftSyncService;
use App\Services\ExpenseService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use App\Services\SalesReportService;
use App\Services\StockOpnameService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Multi-Cabang Lapisan 4 -- Laporan per-cabang. Kasus verifikasi WAJIB dari
 * checklist user, dengan angka nyata: laba-rugi per-cabang berjumlah sama
 * dengan gabungan, omzet per-cabang benar, stok per-cabang benar,
 * perbandingan cabang akurat, Neraca TIDAK per-cabang, dan
 * multi_branch_enabled=false tetap identik sekarang.
 */
class MultiBranchLayer4Test extends TestCase
{
    use RefreshDatabase;

    private BranchService $branches;

    private InventoryService $inventory;

    private SaleService $sales;

    private ExpenseService $expenses;

    private StockOpnameService $opnames;

    private FinancialReportService $financialReports;

    private SalesReportService $salesReports;

    private Warehouse $pusat;

    private Outlet $pusatOutlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $posting = new PostingService();
        $this->branches = new BranchService();
        $this->inventory = new InventoryService();
        $this->sales = new SaleService($this->inventory, $posting, new CashAccountService(), new DraftSyncService($this->branches));
        $this->expenses = new ExpenseService($posting, new CashAccountService());
        $this->opnames = new StockOpnameService($this->inventory, $posting);
        $this->financialReports = new FinancialReportService();
        $this->salesReports = new SalesReportService();

        $this->pusat = Warehouse::first();
        $this->pusatOutlet = Outlet::first();
    }

    private function enableMultiBranch(): void
    {
        CompanySetting::current()->update(['multi_branch_enabled' => true]);
    }

    /**
     * Membangun skenario dua-cabang lengkap: Pusat (revenue 20.000, HPP
     * 6.000, beban 2.000 -> laba bersih 12.000) dan Cabang (revenue 36.000,
     * HPP penjualan 12.000 + selisih stok opname 4.000 = 16.000, beban
     * 1.500 -> laba bersih 18.500). Dipakai oleh beberapa test supaya
     * angka yang diverifikasi konsisten satu sama lain.
     *
     * @return array{0: Outlet, 1: Warehouse, 2: Item}
     */
    private function seedTwoBranchScenario(): array
    {
        [$cabangOutlet, $cabangWarehouse] = $this->makeBranchWithWarehouse('Cabang Timur');
        $item = $this->makeStockedItem();
        $productPusat = $this->makeProductFor($item, 10000);
        $productCabang = $this->makeProductFor($item, 12000);

        // Pusat: stok masuk @Rp3.000, jual 2 @Rp10.000 (HPP 2*3000=6000),
        // beban operasional Rp2.000.
        $this->inventory->recordInbound($item, $this->pusat, '100', '3000', $this->pusatOutlet, '2026-08-13');
        $this->sales->createSale([
            'outlet_id' => $this->pusatOutlet->id,
            'warehouse_id' => $this->pusat->id,
            'date' => '2026-08-13',
            'lines' => [['product_id' => $productPusat->id, 'qty' => 2, 'unit_price' => 10000]],
        ]);
        $this->expenses->recordExpense([
            'outlet_id' => $this->pusatOutlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-08-13',
            'amount' => 2000,
            'payment_method' => 'cash',
            'description' => 'Listrik Pusat',
        ]);

        // Cabang: stok masuk @Rp4.000, jual 3 @Rp12.000 (HPP 3*4000=12000),
        // beban operasional Rp1.500, lalu opname menemukan susut 1 unit
        // (nilai 1*4000=4000, masuk Selisih Persediaan -- akun COGS 5-2xxx).
        $this->inventory->recordInbound($item, $cabangWarehouse, '50', '4000', $this->pusatOutlet, '2026-08-13');
        $this->sales->createSale([
            'outlet_id' => $cabangOutlet->id,
            'warehouse_id' => $cabangWarehouse->id,
            'date' => '2026-08-13',
            'lines' => [['product_id' => $productCabang->id, 'qty' => 3, 'unit_price' => 12000]],
        ]);
        $this->expenses->recordExpense([
            'outlet_id' => $cabangOutlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-08-13',
            'amount' => 1500,
            'payment_method' => 'cash',
            'description' => 'Listrik Cabang',
        ]);
        $opname = $this->opnames->startOpname([
            'warehouse_id' => $cabangWarehouse->id,
            'date' => '2026-08-13',
            'item_ids' => [$item->id],
        ]);
        $line = $opname->lines->first();
        // Sistem sudah tercatat 47 (50 masuk - 3 terjual) -- dihitung 46,
        // susut 1 unit.
        $this->opnames->postOpname($opname, [$line->id => 46], '2026-08-13');

        return [$cabangOutlet, $cabangWarehouse, $item];
    }

    // ==================== Laba-Rugi per-cabang ====================

    public function test_income_statement_by_outlet_sums_exactly_to_the_combined_total(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet] = $this->seedTwoBranchScenario();

        $combined = $this->financialReports->incomeStatement('2026-08-01', '2026-08-31');
        $this->assertSame(0, bccomp($combined['total_revenue'], '56000', 4));
        $this->assertSame(0, bccomp($combined['total_cogs'], '22000', 4));
        $this->assertSame(0, bccomp($combined['total_operational_expense'], '3500', 4));
        $this->assertSame(0, bccomp($combined['net_income'], '30500', 4));

        $pusatReport = $this->financialReports->incomeStatement('2026-08-01', '2026-08-31', $this->pusatOutlet->id);
        $this->assertSame(0, bccomp($pusatReport['total_revenue'], '20000', 4));
        $this->assertSame(0, bccomp($pusatReport['total_cogs'], '6000', 4));
        $this->assertSame(0, bccomp($pusatReport['net_income'], '12000', 4));

        $cabangReport = $this->financialReports->incomeStatement('2026-08-01', '2026-08-31', $cabangOutlet->id);
        $this->assertSame(0, bccomp($cabangReport['total_revenue'], '36000', 4));
        // HPP cabang mencakup penjualan (12000) + selisih opname (4000).
        $this->assertSame(0, bccomp($cabangReport['total_cogs'], '16000', 4));
        $this->assertSame(0, bccomp($cabangReport['net_income'], '18500', 4));

        $byOutlet = collect($this->financialReports->incomeStatementByOutlet('2026-08-01', '2026-08-31'))->keyBy('outlet_id');
        $this->assertCount(2, $byOutlet);

        $summedRevenue = $byOutlet->reduce(fn ($c, $r) => bcadd($c, $r['total_revenue'], 4), '0');
        $summedNetIncome = $byOutlet->reduce(fn ($c, $r) => bcadd($c, $r['net_income'], 4), '0');
        // JAMINAN UTAMA: jumlah semua cabang == total gabungan.
        $this->assertSame(0, bccomp($summedRevenue, $combined['total_revenue'], 4));
        $this->assertSame(0, bccomp($summedNetIncome, $combined['net_income'], 4));

        $this->assertSame(0, bccomp($byOutlet[$this->pusatOutlet->id]['net_income'], '12000', 4));
        $this->assertTrue((bool) $byOutlet[$this->pusatOutlet->id]['is_headquarters']);
        $this->assertSame(0, bccomp($byOutlet[$cabangOutlet->id]['net_income'], '18500', 4));
        $this->assertFalse((bool) $byOutlet[$cabangOutlet->id]['is_headquarters']);
    }

    public function test_income_statement_filtered_by_outlet_agrees_with_the_expense_report_bucket(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet] = $this->seedTwoBranchScenario();

        $user = $this->userWithPermission('laporan.view');
        $response = $this->actingAs($user)->get('/laporan/beban?start=2026-08-01&end=2026-08-31&outlet_id='.$cabangOutlet->id);
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame($cabangOutlet->id, $props['outletId']);
        $this->assertSame(0, bccomp($props['totalExpense'], '1500', 4));
    }

    // ==================== Omzet & laba per-cabang ====================

    public function test_sales_by_outlet_reports_gross_and_sale_only_hpp_separately_from_net_income(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet] = $this->seedTwoBranchScenario();

        $byOutlet = collect($this->salesReports->salesByOutlet('2026-08-01', '2026-08-31'))->keyBy('outlet_id');

        $pusatRow = $byOutlet[$this->pusatOutlet->id];
        $this->assertSame(1, $pusatRow['transaction_count']);
        $this->assertSame(0, bccomp($pusatRow['gross'], '20000', 4));
        $this->assertSame(0, bccomp($pusatRow['hpp'], '6000', 4));
        $this->assertSame(0, bccomp($pusatRow['gross_profit'], '14000', 4));

        $cabangRow = $byOutlet[$cabangOutlet->id];
        $this->assertSame(1, $cabangRow['transaction_count']);
        $this->assertSame(0, bccomp($cabangRow['gross'], '36000', 4));
        // BEDA dari incomeStatementByOutlet: hpp di sini murni sale_lines,
        // TIDAK termasuk selisih opname (4000) -- makanya gross_profit
        // (24000) != net_income cabang (18500) dari test di atas. Dua
        // sumber independen, sengaja tidak sama.
        $this->assertSame(0, bccomp($cabangRow['hpp'], '12000', 4));
        $this->assertSame(0, bccomp($cabangRow['gross_profit'], '24000', 4));
    }

    public function test_combined_sales_report_without_outlet_filter_equals_the_sum_of_all_branches(): void
    {
        $this->enableMultiBranch();
        $this->seedTwoBranchScenario();

        $combined = $this->salesReports->salesReport('2026-08-01', '2026-08-31');
        $this->assertSame(2, $combined['totals']['transaction_count']);
        $this->assertSame(0, bccomp($combined['totals']['gross'], '56000', 4));

        $pusatOnly = $this->salesReports->salesReport('2026-08-01', '2026-08-31', $this->pusatOutlet->id);
        $this->assertSame(1, $pusatOnly['totals']['transaction_count']);
        $this->assertSame(0, bccomp($pusatOnly['totals']['gross'], '20000', 4));
    }

    // ==================== Perbandingan Cabang (halaman) ====================

    public function test_branch_comparison_page_shows_accurate_sales_income_and_stock_value_per_branch(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet, $cabangWarehouse] = $this->seedTwoBranchScenario();

        $user = $this->userWithPermission('laporan.view');
        $response = $this->actingAs($user)->get('/laporan/perbandingan-cabang?start=2026-08-01&end=2026-08-31');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $salesByOutlet = collect($props['salesByOutlet'])->keyBy('outlet_id');
        $incomeByOutlet = collect($props['incomeByOutlet'])->keyBy('outlet_id');
        $stockValueByOutlet = collect($props['stockValueByOutlet'])->keyBy('outlet_id');

        $this->assertSame(0, bccomp($salesByOutlet[$cabangOutlet->id]['gross'], '36000', 4));
        $this->assertSame(0, bccomp($incomeByOutlet[$cabangOutlet->id]['net_income'], '18500', 4));

        // Nilai stok SAAT INI (snapshot): Pusat 98 unit @Rp3.000 = 294.000,
        // Cabang 46 unit @Rp4.000 = 184.000 (avg cost tidak berubah oleh
        // penjualan/opname susut -- hanya qty yang berkurang).
        $this->assertSame(0, bccomp($stockValueByOutlet[$this->pusatOutlet->id]['value'], '294000', 4));
        $this->assertSame(0, bccomp($stockValueByOutlet[$cabangOutlet->id]['value'], '184000', 4));
    }

    // ==================== Stok per-cabang ====================

    public function test_stock_report_isolates_quantity_per_branch_warehouse_and_the_comparison_pivot_shows_both(): void
    {
        $this->enableMultiBranch();
        [, $cabangWarehouse, $item] = $this->seedTwoBranchScenario();

        $user = $this->userWithPermission('laporan.view');

        $pusatResponse = $this->actingAs($user)->get('/laporan/stok?warehouse_id='.$this->pusat->id);
        $pusatRow = $this->stockRowFor($pusatResponse, $item->id);
        $this->assertSame(0, bccomp($pusatRow['stock'], '98', 4));

        $cabangResponse = $this->actingAs($user)->get('/laporan/stok?warehouse_id='.$cabangWarehouse->id);
        $cabangRow = $this->stockRowFor($cabangResponse, $item->id);
        $this->assertSame(0, bccomp($cabangRow['stock'], '46', 4));

        $pivot = collect($cabangResponse->viewData('page')['props']['stockByWarehouse'])
            ->filter(fn ($row) => $row['item_id'] === $item->id)
            ->keyBy('warehouse_id');
        $this->assertSame(0, bccomp($pivot[$this->pusat->id]['stock'], '98', 4));
        $this->assertSame(0, bccomp($pivot[$cabangWarehouse->id]['stock'], '46', 4));
    }

    // ==================== Neraca: TIDAK per-cabang, jujur ====================

    public function test_balance_sheet_accepts_no_outlet_parameter_by_design(): void
    {
        // Jaminan level KODE (bukan cuma UI) -- balanceSheet() cuma boleh
        // punya SATU parameter ($asOfDate). Kalau suatu saat ada yang
        // menambahkan filter cabang ke sini, test ini pecah duluan.
        $method = new ReflectionMethod(FinancialReportService::class, 'balanceSheet');
        $this->assertCount(1, $method->getParameters());
    }

    public function test_balance_sheet_stays_balanced_and_combined_regardless_of_branch_activity(): void
    {
        $this->enableMultiBranch();
        $this->seedTwoBranchScenario();

        $report = $this->financialReports->balanceSheet('2026-08-31');
        $this->assertTrue($report['is_balanced'], 'Neraca gabungan harus tetap seimbang walau ada transaksi lintas cabang.');
    }

    public function test_cash_by_outlet_reports_the_branch_scoped_kas_account_without_altering_the_balance_sheet_totals(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet] = $this->makeBranchWithWarehouse('Cabang Kas');
        $cashAccounts = new CashAccountService();
        $branchKas = $cashAccounts->createBankAccount('1-1150', 'Kas Cabang Kas', $cabangOutlet->id);

        $reportBefore = $this->financialReports->balanceSheet('2026-08-31');

        $this->expenses->recordExpense([
            'outlet_id' => $cabangOutlet->id,
            'expense_account_id' => Account::where('code', '5-3000')->firstOrFail()->id,
            'date' => '2026-08-13',
            'amount' => 1500,
            'payment_method' => 'cash',
            'cash_account_code' => '1-1150',
            'description' => 'Listrik dibayar dari Kas Cabang',
        ]);

        $reportAfter = $this->financialReports->balanceSheet('2026-08-31');
        // Neraca GABUNGAN tetap seimbang (dan totalnya bergerak dengan
        // transaksi seperti biasa) -- tidak ada logika baru per-cabang di
        // dalamnya.
        $this->assertTrue($reportAfter['is_balanced']);
        $this->assertSame(
            0,
            bccomp(bcsub($reportBefore['total_assets'], $reportAfter['total_assets'], 4), '1500', 4),
        );

        $cashByOutlet = collect($this->financialReports->cashByOutlet('2026-08-31'))->keyBy('outlet_id');
        $this->assertTrue($cashByOutlet->has($cabangOutlet->id));
        $row = $cashByOutlet[$cabangOutlet->id];
        $this->assertSame($branchKas->code, $row['account_code']);
        $this->assertSame(0, bccomp($row['balance'], '-1500', 4), 'Kas cabang berkurang persis sebesar beban yang dibayar darinya.');
    }

    public function test_balance_sheet_page_labels_the_cash_detail_as_a_detail_not_a_separate_branch_balance_sheet(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet] = $this->makeBranchWithWarehouse('Cabang Label');
        (new CashAccountService())->createBankAccount('1-1160', 'Kas Cabang Label', $cabangOutlet->id);

        $user = $this->userWithPermission('laporan.view');
        $response = $this->actingAs($user)->get('/laporan/neraca?as_of=2026-08-31');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['multiBranchEnabled']);
        $this->assertNotEmpty($props['cashByOutlet']);
        // Props Neraca itu sendiri (report) sama sekali tidak punya bentuk
        // per-cabang -- hanya assets/liabilities/equity gabungan biasa.
        $this->assertArrayNotHasKey('outletId', $props);
        $this->assertArrayNotHasKey('outlets', $props);
    }

    // ==================== Kompatibilitas: multi_branch_enabled=false ====================

    public function test_disabled_toggle_hides_branch_filter_props_but_combined_totals_stay_correct(): void
    {
        // multi_branch_enabled TETAP default false -- tidak dipanggil
        // enableMultiBranch().
        $this->assertFalse(CompanySetting::current()->multi_branch_enabled);

        $item = $this->makeStockedItem();
        $product = $this->makeProductFor($item, 10000);
        $this->inventory->recordInbound($item, $this->pusat, '10', '5000', $this->pusatOutlet, '2026-08-13');
        $this->sales->createSale([
            'outlet_id' => $this->pusatOutlet->id,
            'warehouse_id' => $this->pusat->id,
            'date' => '2026-08-13',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $user = $this->userWithPermission('laporan.view');

        $salesResponse = $this->actingAs($user)->get('/laporan/penjualan?start=2026-08-01&end=2026-08-31');
        $salesResponse->assertOk();
        $salesProps = $salesResponse->viewData('page')['props'];
        $this->assertFalse($salesProps['multiBranchEnabled']);
        $this->assertNull($salesProps['outletId']);
        $this->assertSame(0, bccomp($salesProps['report']['totals']['gross'], '10000', 4));

        $incomeResponse = $this->actingAs($user)->get('/laporan/laba-rugi?start=2026-08-01&end=2026-08-31');
        $incomeProps = $incomeResponse->viewData('page')['props'];
        $this->assertFalse($incomeProps['multiBranchEnabled']);
        $this->assertSame(0, bccomp($incomeProps['report']['total_revenue'], '10000', 4));

        $balanceResponse = $this->actingAs($user)->get('/laporan/neraca?as_of=2026-08-31');
        $balanceProps = $balanceResponse->viewData('page')['props'];
        $this->assertFalse($balanceProps['multiBranchEnabled']);
        // Tidak ada akun Kas ber-outlet_id sama sekali -- rincian kosong,
        // bukan data palsu.
        $this->assertSame([], $balanceProps['cashByOutlet']);
    }

    // ==================== Helpers ====================

    private function stockRowFor($response, int $itemId): array
    {
        foreach ($response->viewData('page')['props']['items'] as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        $this->fail("Item #{$itemId} not found in response.");
    }

    private function userWithPermission(string $key): User
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach($permission->id);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeBranch(string $name): Outlet
    {
        return $this->branches->createOutlet([
            'name' => $name,
            'code' => null,
            'address' => null,
            'is_active' => true,
            'is_headquarters' => false,
        ]);
    }

    /** @return array{0: Outlet, 1: Warehouse} */
    private function makeBranchWithWarehouse(string $name): array
    {
        $outlet = $this->makeBranch($name);

        return [$outlet, Warehouse::where('outlet_id', $outlet->id)->firstOrFail()];
    }

    private function makeStockedItem(): Item
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $persediaanAccount = Account::where('code', '1-1200')->firstOrFail();

        return Item::create([
            'sku' => 'ITEM-'.uniqid(),
            'name' => 'Item Test',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $persediaanAccount->id,
        ]);
    }

    private function makeProductFor(Item $item, int $sellPrice): Product
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $product = Product::create(['name' => 'Produk '.uniqid(), 'sell_price' => $sellPrice]);
        ProductComponent::create([
            'product_id' => $product->id,
            'item_id' => $item->id,
            'uom_id' => $pcs->id,
            'qty' => 1,
        ]);

        return $product;
    }
}
