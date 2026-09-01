<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\CashAccountService;
use App\Services\CashTransferService;
use App\Services\DraftSyncService;
use App\Services\EquityTransactionService;
use App\Services\ExpensePayableReportService;
use App\Services\ExpensePaymentService;
use App\Services\ExpenseService;
use App\Services\FinancialReportService;
use App\Services\FixedAssetService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private PostingService $posting;

    private FinancialReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->posting = new PostingService();
        $this->reports = new FinancialReportService();
    }

    /**
     * @return array{0: Item, 1: Supplier, 2: Warehouse}
     */
    private function makeWidgetItemAndSupplier(): array
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $persediaanAccount = Account::where('code', '1-1200')->firstOrFail();

        $item = Item::create([
            'sku' => 'WIDGET-CF',
            'name' => 'Widget Arus Kas',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $persediaanAccount->id,
        ]);
        $supplier = Supplier::create(['name' => 'Supplier Arus Kas']);
        $warehouse = Warehouse::first();

        return [$item, $supplier, $warehouse];
    }

    public function test_all_account_balances_includes_every_account_across_all_types_including_the_group_header(): void
    {
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 500000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 500000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Penjualan tunai',
        );

        $rows = collect($this->reports->allAccountBalances('2026-07-01'))->keyBy('code');

        // Beda dari accountsForType()/balanceSheet(): header grup "Kas &
        // Bank" (1-1) IKUT tampil di sini (perlu untuk hierarki tampilan
        // CoA), bukan disembunyikan.
        $this->assertTrue($rows->has('1-1'));

        $this->assertSame(0, bccomp($rows['1-1000']['balance'], '500000', 4));
        $this->assertSame(0, bccomp($rows['4-1000']['balance'], '500000', 4));
        $this->assertSame('asset', $rows['1-1000']['type']);
        $this->assertSame('debit', $rows['1-1000']['normal_balance']);

        // Akun tanpa jurnal apa pun tetap muncul dengan saldo nol, bukan hilang.
        $this->assertSame(0, bccomp($rows['1-1200']['balance'], '0', 4));
    }

    public function test_balance_sheet_nets_aset_tetap_and_akumulasi_penyusutan_into_correct_nilai_buku(): void
    {
        // Beli aset tunai 12.000.000 (Dr Aset Tetap / Cr Kas).
        $this->posting->post(
            lines: [
                ['account' => '1-2000', 'debit' => 12000000, 'credit' => 0],
                ['account' => '1-1000', 'debit' => 0, 'credit' => 12000000],
            ],
            date: '2026-01-01',
            source: Outlet::first(),
            memo: 'Beli aset',
        );

        // Penyusutan bulan pertama 250.000 (Dr Beban Penyusutan / Cr Akumulasi Penyusutan).
        $this->posting->post(
            lines: [
                ['account' => '5-4000', 'debit' => 250000, 'credit' => 0],
                ['account' => '1-2900', 'debit' => 0, 'credit' => 250000],
            ],
            date: '2026-02-01',
            source: Outlet::first(),
            memo: 'Penyusutan',
        );

        $report = $this->reports->balanceSheet('2026-02-01');

        $assetsByCode = collect($report['assets'])->keyBy('code');

        $this->assertSame(0, bccomp($assetsByCode['1-2000']['balance'], '12000000', 4));
        // Akumulasi Penyusutan tampil NEGATIF (kontra-aset) -- hasil dari
        // normal_balance='debit' yang sama dengan Aset Tetap, meski
        // transaksinya selalu mengkredit akun ini.
        $this->assertSame(0, bccomp($assetsByCode['1-2900']['balance'], '-250000', 4));

        $this->assertTrue($report['is_balanced']);
    }

    public function test_beban_penyusutan_lands_in_the_operational_bucket_not_cogs(): void
    {
        $this->posting->post(
            lines: [
                ['account' => '5-4000', 'debit' => 250000, 'credit' => 0],
                ['account' => '1-2900', 'debit' => 0, 'credit' => 250000],
            ],
            date: '2026-02-01',
            source: Outlet::first(),
            memo: 'Penyusutan',
        );

        $report = $this->reports->incomeStatement('2026-02-01', '2026-02-28');

        $cogsCodes = collect($report['cogs_expenses'])->pluck('code')->all();
        $operationalCodes = collect($report['operational_expenses'])->pluck('code')->all();

        $this->assertNotContains('5-4000', $cogsCodes);
        $this->assertContains('5-4000', $operationalCodes);
        $this->assertSame(0, bccomp($report['total_operational_expense'], '250000', 4));
    }

    public function test_balance_sheet_nets_modal_and_prive_into_correct_equity_total(): void
    {
        // Setoran modal 1.000.000 (Dr Kas / Cr Modal).
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 1000000, 'credit' => 0],
                ['account' => '3-1000', 'debit' => 0, 'credit' => 1000000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Setoran modal',
        );

        // Prive 300.000 (Dr Prive / Cr Kas).
        $this->posting->post(
            lines: [
                ['account' => '3-2000', 'debit' => 300000, 'credit' => 0],
                ['account' => '1-1000', 'debit' => 0, 'credit' => 300000],
            ],
            date: '2026-07-02',
            source: Outlet::first(),
            memo: 'Prive',
        );

        $report = $this->reports->balanceSheet('2026-07-02');

        $equityByCode = collect($report['equity'])->keyBy('code');

        // Modal tampil positif, Prive tampil NEGATIF (kontra-ekuitas) --
        // hasil dari normal_balance='credit' yang di-seed untuk Prive.
        $this->assertSame(0, bccomp($equityByCode['3-1000']['balance'], '1000000', 4));
        $this->assertSame(0, bccomp($equityByCode['3-2000']['balance'], '-300000', 4));

        // Total ekuitas = Modal - Prive = 700.000 (tidak ada laba/rugi
        // berjalan di sini karena tidak ada jurnal pendapatan/beban).
        $this->assertSame(0, bccomp($report['total_equity'], '700000', 4));

        // Kas berkurang 300.000 dari Prive, jadi total aset = 700.000 --
        // Neraca harus tetap seimbang murni dari dua jurnal yang masing-masing sudah balanced sendiri.
        $this->assertSame(0, bccomp($report['total_assets'], '700000', 4));
        $this->assertTrue($report['is_balanced']);
    }

    public function test_prive_never_appears_in_the_income_statement(): void
    {
        $this->posting->post(
            lines: [
                ['account' => '3-2000', 'debit' => 300000, 'credit' => 0],
                ['account' => '1-1000', 'debit' => 0, 'credit' => 300000],
            ],
            date: '2026-07-02',
            source: Outlet::first(),
            memo: 'Prive',
        );

        $report = $this->reports->incomeStatement('2026-07-01', '2026-07-31');

        $expenseCodes = collect($report['expenses'])->pluck('code')->all();
        $this->assertNotContains('3-2000', $expenseCodes);
        // Prive tidak menyentuh pendapatan/beban -- laba bersih harus tetap nol.
        $this->assertSame(0, bccomp($report['net_income'], '0', 4));
    }

    public function test_balance_sheet_shows_kas_and_bank_as_separate_lines_but_excludes_the_group_header(): void
    {
        $this->posting->post(
            lines: [
                ['account' => '1-1100', 'debit' => 200000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 200000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Penjualan via bank',
        );

        $report = $this->reports->balanceSheet('2026-07-01');

        $assetsByCode = collect($report['assets'])->keyBy('code');
        $this->assertSame(0, bccomp($assetsByCode['1-1100']['balance'], '200000', 4));
        $this->assertSame(0, bccomp($assetsByCode['1-1000']['balance'], '0', 4));
        // Akun header "Kas & Bank" (kode "1-1") murni struktural -- tidak
        // pernah diposting, tidak boleh muncul sebagai baris terpisah.
        $this->assertFalse($assetsByCode->has('1-1'));
        $this->assertTrue($report['is_balanced']);
    }

    public function test_balance_sheet_balances_using_unclosed_net_income_as_equity(): void
    {
        // Dr Kas 100000, Cr Penjualan 100000 (penjualan tunai sederhana, tanpa pajak/HPP).
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 100000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 100000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Penjualan tunai',
        );

        $report = $this->reports->balanceSheet('2026-07-01');

        $assetsByCode = collect($report['assets'])->keyBy('code');
        $this->assertSame(0, bccomp($assetsByCode['1-1000']['balance'], '100000', 4));
        $this->assertSame(0, bccomp($report['total_assets'], '100000', 4));
        $this->assertSame(0, bccomp($report['total_liabilities'], '0', 4));

        // Belum ada akun ekuitas di CoA — satu-satunya isi Equity adalah baris
        // "Laba/Rugi Berjalan" virtual sebesar net income (100000 - 0).
        $currentEarningsLine = collect($report['equity'])->firstWhere('code', null);
        $this->assertNotNull($currentEarningsLine);
        $this->assertSame(0, bccomp($currentEarningsLine['balance'], '100000', 4));

        $this->assertSame(0, bccomp($report['total_equity'], '100000', 4));
        $this->assertSame(0, bccomp($report['total_liabilities_and_equity'], '100000', 4));
        $this->assertTrue($report['is_balanced']);
    }

    public function test_balance_sheet_excludes_journals_after_the_as_of_date(): void
    {
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 100000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 100000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Penjualan tunai',
        );

        // HPP mengurangi Persediaan, terjadi bulan berikutnya.
        $this->posting->post(
            lines: [
                ['account' => '5-1000', 'debit' => 30000, 'credit' => 0],
                ['account' => '1-1200', 'debit' => 0, 'credit' => 30000],
            ],
            date: '2026-08-01',
            source: Outlet::first(),
            memo: 'HPP',
        );

        $reportBefore = $this->reports->balanceSheet('2026-07-01');
        $this->assertSame(0, bccomp($reportBefore['total_assets'], '100000', 4));
        $this->assertTrue($reportBefore['is_balanced']);

        $reportAfter = $this->reports->balanceSheet('2026-08-01');
        // Kas 100000 + Persediaan (-30000) = 70000
        $this->assertSame(0, bccomp($reportAfter['total_assets'], '70000', 4));
        $this->assertSame(0, bccomp($reportAfter['total_equity'], '70000', 4));
        $this->assertTrue($reportAfter['is_balanced']);
    }

    public function test_income_statement_computes_net_income_for_a_date_range(): void
    {
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 100000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 100000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Penjualan tunai',
        );

        $this->posting->post(
            lines: [
                ['account' => '5-1000', 'debit' => 30000, 'credit' => 0],
                ['account' => '1-1200', 'debit' => 0, 'credit' => 30000],
            ],
            date: '2026-08-01',
            source: Outlet::first(),
            memo: 'HPP',
        );

        $julyReport = $this->reports->incomeStatement('2026-07-01', '2026-07-31');
        $this->assertSame(0, bccomp($julyReport['total_revenue'], '100000', 4));
        $this->assertSame(0, bccomp($julyReport['total_expense'], '0', 4));
        $this->assertSame(0, bccomp($julyReport['net_income'], '100000', 4));

        $augustReport = $this->reports->incomeStatement('2026-08-01', '2026-08-31');
        $this->assertSame(0, bccomp($augustReport['total_revenue'], '0', 4));
        $this->assertSame(0, bccomp($augustReport['total_expense'], '30000', 4));
        $this->assertSame(0, bccomp($augustReport['net_income'], '-30000', 4));
    }

    public function test_income_statement_splits_cogs_from_operational_expenses_for_gross_profit(): void
    {
        // Penjualan 500000, HPP 200000 (COGS), Beban Listrik 50000 (operasional).
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 500000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 500000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Penjualan tunai',
        );

        $this->posting->post(
            lines: [
                ['account' => '5-1000', 'debit' => 200000, 'credit' => 0],
                ['account' => '1-1200', 'debit' => 0, 'credit' => 200000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'HPP',
        );

        $this->posting->post(
            lines: [
                ['account' => '5-3000', 'debit' => 50000, 'credit' => 0],
                ['account' => '1-1000', 'debit' => 0, 'credit' => 50000],
            ],
            date: '2026-07-01',
            source: Outlet::first(),
            memo: 'Beban Listrik',
        );

        $report = $this->reports->incomeStatement('2026-07-01', '2026-07-31');

        $this->assertSame(0, bccomp($report['total_cogs'], '200000', 4));
        $this->assertSame(0, bccomp($report['gross_profit'], '300000', 4)); // 500000 - 200000
        $this->assertSame(0, bccomp($report['total_operational_expense'], '50000', 4));

        // Backward compat: total_expense/net_income tetap agregat lama
        // (200000 + 50000 = 250000), tidak berubah oleh pengelompokan baru.
        $this->assertSame(0, bccomp($report['total_expense'], '250000', 4));
        $this->assertSame(0, bccomp($report['net_income'], '250000', 4)); // 500000 - 250000

        // gross_profit - total_operational_expense harus persis sama dengan net_income.
        $this->assertSame(
            0,
            bccomp(bcsub($report['gross_profit'], $report['total_operational_expense'], 4), $report['net_income'], 4),
        );

        $cogsCodes = collect($report['cogs_expenses'])->pluck('code');
        $this->assertTrue($cogsCodes->contains('5-1000'));
        $this->assertFalse($cogsCodes->contains('5-3000'));

        $operationalCodes = collect($report['operational_expenses'])->pluck('code');
        $this->assertTrue($operationalCodes->contains('5-3000'));
        $this->assertFalse($operationalCodes->contains('5-1000'));
    }

    public function test_cash_flow_classifies_a_cash_sale_as_an_operating_receipt(): void
    {
        $sales = new SaleService(
            new InventoryService(),
            $this->posting,
            new CashAccountService(),
            new DraftSyncService(new BranchService()),
        );
        $outlet = Outlet::first();
        $warehouse = Warehouse::first();
        $product = Product::create(['name' => 'Kopi Arus Kas', 'sell_price' => 20000]);

        $sales->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-07-10',
            'payment_method' => 'cash',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 20000]],
        ]);

        $report = $this->reports->cashFlowStatement('2026-07-01', '2026-07-31');

        $this->assertSame(0, bccomp($report['total_operating'], '20000', 4));
        $this->assertSame(0, bccomp($report['total_investing'], '0', 4));
        $this->assertSame(0, bccomp($report['total_financing'], '0', 4));
        $rows = collect($report['operating'])->keyBy('label');
        $this->assertSame(0, bccomp($rows['Penerimaan dari Penjualan']['balance'], '20000', 4));
        $this->assertTrue($report['is_balanced']);
    }

    public function test_cash_flow_separates_cash_purchase_from_supplier_debt_payment(): void
    {
        [$item, $supplier, $warehouse] = $this->makeWidgetItemAndSupplier();
        $purchases = new PurchaseService(new InventoryService(), $this->posting, new CashAccountService());
        $payments = new SupplierPaymentService($this->posting, new CashAccountService());
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        // Pembelian TUNAI langsung -- credit Kas langsung, tidak lewat Hutang Usaha.
        $poCash = $purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-07-05',
            'lines' => [['item_id' => $item->id, 'qty' => '5', 'purchase_uom_id' => $pcs->id, 'unit_price' => '10000']],
        ]);
        $purchases->receiveGoods($poCash, [$poCash->lines->first()->id => '5'], '2026-07-05', 'cash');

        // Pembelian KREDIT, lalu dilunasi terpisah -- harus muncul sebagai
        // "Pembayaran Hutang Usaha", BUKAN "Pembayaran Pembelian Tunai".
        $poCredit = $purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-07-06',
            'lines' => [['item_id' => $item->id, 'qty' => '3', 'purchase_uom_id' => $pcs->id, 'unit_price' => '10000']],
        ]);
        $receiptCredit = $purchases->receiveGoods($poCredit, [$poCredit->lines->first()->id => '3'], '2026-07-06', 'credit');
        $payments->recordPayment([
            'outlet_id' => Outlet::first()->id,
            'supplier_id' => $supplier->id,
            'date' => '2026-07-15',
            'amount' => 30000,
            'allocations' => [['goods_receipt_id' => $receiptCredit->id, 'amount' => 30000]],
        ]);

        $report = $this->reports->cashFlowStatement('2026-07-01', '2026-07-31');
        $rows = collect($report['operating'])->keyBy('label');

        // Kredit (bukan kas) -- pembelian kredit sendiri TIDAK PERNAH
        // menyentuh akun Kas/Bank, jadi cuma pelunasannya yang muncul.
        $this->assertSame(0, bccomp($rows['Pembayaran Pembelian Tunai']['balance'], '-50000', 4));
        $this->assertSame(0, bccomp($rows['Pembayaran Hutang Usaha (Supplier)']['balance'], '-30000', 4));
        $this->assertSame(0, bccomp($report['total_operating'], '-80000', 4));
    }

    public function test_cash_flow_classifies_fixed_asset_purchase_as_investing_and_equity_as_financing(): void
    {
        $fixedAssets = new FixedAssetService($this->posting, new CashAccountService());
        $equity = new EquityTransactionService($this->posting, new CashAccountService());
        $outlet = Outlet::first();

        $fixedAssets->recordPurchase([
            'outlet_id' => $outlet->id,
            'name' => 'Kulkas Arus Kas',
            'category' => 'Peralatan',
            'purchase_date' => '2026-07-01',
            'acquisition_cost' => 5000000,
            'residual_value' => 0,
            'useful_life_months' => 48,
            'payment_method' => 'cash',
        ]);
        $equity->recordModalDeposit([
            'outlet_id' => $outlet->id,
            'date' => '2026-07-02',
            'amount' => 8000000,
            'description' => 'Setoran awal',
        ]);
        $equity->recordPriveWithdrawal([
            'outlet_id' => $outlet->id,
            'date' => '2026-07-03',
            'amount' => 1000000,
            'description' => 'Keperluan pribadi',
        ]);

        $report = $this->reports->cashFlowStatement('2026-07-01', '2026-07-31');

        $investingRows = collect($report['investing'])->keyBy('label');
        $this->assertSame(0, bccomp($investingRows['Pembelian Aset Tetap Tunai']['balance'], '-5000000', 4));
        $this->assertSame(0, bccomp($report['total_investing'], '-5000000', 4));

        $financingRows = collect($report['financing'])->keyBy('label');
        $this->assertSame(0, bccomp($financingRows['Setoran Modal Pemilik']['balance'], '8000000', 4));
        $this->assertSame(0, bccomp($financingRows['Pengambilan Prive']['balance'], '-1000000', 4));
        $this->assertSame(0, bccomp($report['total_financing'], '7000000', 4));
    }

    /**
     * Kedua kaki CashTransfer sama-sama akun Kas/Bank internal (Kas ->
     * Bank) -- bukan arus kas EKSTERNAL, jadi harus SAMA SEKALI tidak
     * muncul di kategori manapun, bukan di-net jadi nol secara kebetulan.
     */
    public function test_cash_flow_excludes_cash_transfer_entirely(): void
    {
        $transfers = new CashTransferService($this->posting, new CashAccountService());
        $outlet = Outlet::first();

        $transfers->recordTransfer([
            'outlet_id' => $outlet->id,
            'date' => '2026-07-10',
            'from_account_code' => '1-1000',
            'to_account_code' => '1-1100',
            'amount' => 2000000,
            'memo' => 'Setor ke bank',
        ]);

        $report = $this->reports->cashFlowStatement('2026-07-01', '2026-07-31');

        $this->assertSame([], $report['operating']);
        $this->assertSame([], $report['investing']);
        $this->assertSame([], $report['financing']);
        $this->assertSame(0, bccomp($report['net_change'], '0', 4));
        // Saldo kas GABUNGAN (Kas+Bank) tidak berubah oleh transfer
        // internal -- awal & akhir harus tetap sama persis.
        $this->assertSame(0, bccomp($report['beginning_cash'], $report['ending_cash'], 4));
        $this->assertTrue($report['is_balanced']);
    }

    /**
     * Skenario gabungan lintas SEMUA jenis aktivitas dalam satu laporan --
     * membuktikan Saldo Awal + Kenaikan Bersih benar-benar sama dengan
     * saldo Kas/Bank sungguhan (is_balanced), bukan cuma benar per jenis
     * transaksi yang diuji terpisah-pisah di atas.
     */
    public function test_cash_flow_reconciles_beginning_plus_net_change_to_the_actual_ending_balance(): void
    {
        // Saldo AWAL dari transaksi bulan SEBELUMNYA (Juni) -- harus ikut
        // terbawa sebagai beginning_cash Juli, walau di luar rentang laporan.
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 1000000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 1000000],
            ],
            date: '2026-06-15',
            source: Outlet::first(),
            memo: 'Penjualan Juni',
        );

        $expenses = new ExpenseService($this->posting, new CashAccountService());
        $expenses->recordExpense([
            'outlet_id' => Outlet::first()->id,
            'expense_account_id' => Account::where('code', '5-3200')->firstOrFail()->id,
            'date' => '2026-07-05',
            'amount' => 400000,
            'payment_method' => 'cash',
            'description' => 'Gaji Juli',
        ]);

        $report = $this->reports->cashFlowStatement('2026-07-01', '2026-07-31');

        $this->assertSame(0, bccomp($report['beginning_cash'], '1000000', 4));
        $this->assertSame(0, bccomp($report['total_operating'], '-400000', 4));
        $this->assertSame(0, bccomp($report['ending_cash'], '600000', 4));
        $this->assertSame(0, bccomp($report['ending_cash'], $report['actual_ending_cash'], 4));
        $this->assertTrue($report['is_balanced']);
    }

    public function test_cash_flow_for_a_range_without_any_transactions_still_carries_over_the_prior_balance(): void
    {
        $this->posting->post(
            lines: [
                ['account' => '1-1000', 'debit' => 750000, 'credit' => 0],
                ['account' => '4-1000', 'debit' => 0, 'credit' => 750000],
            ],
            date: '2026-06-01',
            source: Outlet::first(),
            memo: 'Penjualan Juni',
        );

        $report = $this->reports->cashFlowStatement('2026-08-01', '2026-08-31');

        $this->assertSame([], $report['operating']);
        $this->assertSame([], $report['investing']);
        $this->assertSame([], $report['financing']);
        $this->assertSame(0, bccomp($report['net_change'], '0', 4));
        $this->assertSame(0, bccomp($report['beginning_cash'], '750000', 4));
        $this->assertSame(0, bccomp($report['ending_cash'], '750000', 4));
        $this->assertTrue($report['is_balanced']);
    }
}
