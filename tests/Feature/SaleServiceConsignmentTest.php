<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ConsignmentAccrual;
use App\Models\Item;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\CashAccountService;
use App\Services\DraftSyncService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fitur Konsinyasi -- bagian SaleService (lihat rancangan yang disetujui).
 * Pola setUp()/helper PERSIS SaleServiceTest (sengaja file terpisah supaya
 * diff lebih kecil & gampang direview, bukan ditambahkan ke file itu).
 *
 * Stok item konsinyasi diberikan lewat InventoryService::recordInbound()
 * LANGSUNG (pola sama test timezone di SaleServiceTest) -- cukup untuk
 * menguji SaleService itu sendiri, yang tidak peduli apakah stok itu
 * datang dari Terima Titipan sungguhan (ConsignmentServiceTest) atau
 * bukan.
 */
class SaleServiceConsignmentTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_PERSEDIAAN = '1-1200';

    private const ACCOUNT_HPP = '5-1000';

    private const ACCOUNT_HUTANG_KONSINYASI = '2-3000';

    private InventoryService $inventory;

    private SaleService $sales;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Uom $pcs;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->sales = new SaleService($this->inventory, new PostingService(), new CashAccountService(), new DraftSyncService(new BranchService()));

        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    private function makeConsignmentProduct(Supplier $supplier, string $sellPrice): array
    {
        $item = Item::create([
            'sku' => $this->uniqueCode('KONSI'),
            'name' => 'Item Konsinyasi',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
            'is_consignment' => true,
            'consignment_supplier_id' => $supplier->id,
        ]);
        $product = Product::create(['name' => 'Produk Konsinyasi', 'sell_price' => $sellPrice]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        return [$item, $product];
    }

    private function makeOwnedProduct(string $sellPrice): array
    {
        $item = Item::create([
            'sku' => $this->uniqueCode('MILIK'),
            'name' => 'Item Milik Sendiri',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
        $product = Product::create(['name' => 'Produk Milik Sendiri', 'sell_price' => $sellPrice]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        return [$item, $product];
    }

    private function makeOpeningBalanceSource(): Outlet
    {
        return Outlet::create(['name' => 'Opening Balance '.(++self::$seq)]);
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix.'-'.(++self::$seq);
    }

    private function journalLinesByAccountCode(Journal $journal): \Illuminate\Support\Collection
    {
        return $journal->lines()->with('account')->get()->groupBy(fn (JournalLine $line) => $line->account->code);
    }

    public function test_selling_a_pure_consignment_item_credits_hutang_konsinyasi_not_persediaan(): void
    {
        $supplier = Supplier::create(['name' => 'Pemilik Titipan']);
        [$item, $product] = $this->makeConsignmentProduct($supplier, '10000');
        $this->inventory->recordInbound($item, $this->warehouse, 24, '8000', $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $byAccount = $this->journalLinesByAccountCode($journal);

        $this->assertArrayNotHasKey(self::ACCOUNT_PERSEDIAAN, $byAccount->all(), 'Persediaan TIDAK boleh dikredit -- barang ini bukan aset milik toko.');
        $this->assertSame(0, bccomp($byAccount[self::ACCOUNT_HPP]->first()->debit, '8000', 4));
        $this->assertSame(0, bccomp($byAccount[self::ACCOUNT_HUTANG_KONSINYASI]->first()->credit, '8000', 4));

        $totalDebit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 4), '0');
        $totalCredit = $journal->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 4), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4));

        $accrual = ConsignmentAccrual::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame($supplier->id, $accrual->supplier_id);
        $this->assertSame(0, bccomp($accrual->amount, '8000', 4));
        $this->assertNull($accrual->voided_at);
    }

    public function test_selling_a_mix_of_consignment_and_owned_items_splits_the_credit_correctly(): void
    {
        $supplier = Supplier::create(['name' => 'Pemilik Titipan']);
        [$consignmentItem, $consignmentProduct] = $this->makeConsignmentProduct($supplier, '10000');
        [$ownedItem, $ownedProduct] = $this->makeOwnedProduct('6000');

        $this->inventory->recordInbound($consignmentItem, $this->warehouse, 10, '8000', $this->makeOpeningBalanceSource(), '2026-07-01');
        $this->inventory->recordInbound($ownedItem, $this->warehouse, 10, '3000', $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [
                ['product_id' => $consignmentProduct->id, 'qty' => 1, 'unit_price' => 10000],
                ['product_id' => $ownedProduct->id, 'qty' => 1, 'unit_price' => 6000],
            ],
        ]);

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $byAccount = $this->journalLinesByAccountCode($journal);

        $this->assertSame(0, bccomp($byAccount[self::ACCOUNT_HPP]->first()->debit, '11000', 4)); // 8000 + 3000
        $this->assertSame(0, bccomp($byAccount[self::ACCOUNT_PERSEDIAAN]->first()->credit, '3000', 4)); // porsi milik sendiri saja
        $this->assertSame(0, bccomp($byAccount[self::ACCOUNT_HUTANG_KONSINYASI]->first()->credit, '8000', 4)); // porsi konsinyasi saja

        $this->assertSame(1, ConsignmentAccrual::where('sale_id', $sale->id)->count());
    }

    public function test_selling_items_from_two_different_consignment_suppliers_in_one_sale_creates_separate_credit_lines_and_accruals(): void
    {
        $supplierA = Supplier::create(['name' => 'Pemilik A']);
        $supplierB = Supplier::create(['name' => 'Pemilik B']);
        [$itemA, $productA] = $this->makeConsignmentProduct($supplierA, '10000');
        [$itemB, $productB] = $this->makeConsignmentProduct($supplierB, '12000');

        $this->inventory->recordInbound($itemA, $this->warehouse, 10, '8000', $this->makeOpeningBalanceSource(), '2026-07-01');
        $this->inventory->recordInbound($itemB, $this->warehouse, 10, '9000', $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [
                ['product_id' => $productA->id, 'qty' => 1, 'unit_price' => 10000],
                ['product_id' => $productB->id, 'qty' => 1, 'unit_price' => 12000],
            ],
        ]);

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $byAccount = $this->journalLinesByAccountCode($journal);

        // Dua baris kredit TERPISAH ke akun yang SAMA (2-3000) -- satu per
        // supplier, bukan digabung jadi satu baris.
        $konsinyasiLines = $byAccount[self::ACCOUNT_HUTANG_KONSINYASI];
        $this->assertCount(2, $konsinyasiLines);
        $credits = $konsinyasiLines->pluck('credit')->map(fn ($v) => (string) $v)->sort()->values();
        $this->assertSame(0, bccomp($credits[0], '8000', 4));
        $this->assertSame(0, bccomp($credits[1], '9000', 4));

        $this->assertArrayNotHasKey(self::ACCOUNT_PERSEDIAAN, $byAccount->all());

        $accruals = ConsignmentAccrual::where('sale_id', $sale->id)->get()->keyBy('supplier_id');
        $this->assertCount(2, $accruals);
        $this->assertSame(0, bccomp($accruals[$supplierA->id]->amount, '8000', 4));
        $this->assertSame(0, bccomp($accruals[$supplierB->id]->amount, '9000', 4));
    }

    public function test_voiding_a_consignment_sale_reverses_the_journal_and_marks_the_accrual_voided(): void
    {
        $supplier = Supplier::create(['name' => 'Pemilik Titipan']);
        [$item, $product] = $this->makeConsignmentProduct($supplier, '10000');
        $this->inventory->recordInbound($item, $this->warehouse, 24, '8000', $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $this->sales->voidSale($sale, 'uji coba batal', null);

        $accrual = ConsignmentAccrual::where('sale_id', $sale->id)->firstOrFail();
        $this->assertNotNull($accrual->voided_at);

        $reversalJournal = Journal::where('source_type', \App\Models\SaleVoid::class)->firstOrFail();
        $byAccount = $this->journalLinesByAccountCode($reversalJournal);

        // Jurnal pembalik: baris yang asalnya kredit 2-3000 sekarang DEBIT
        // 2-3000 -- dibuktikan generik lewat loop pembalikan voidSale() yang
        // TIDAK diubah sama sekali oleh fitur ini.
        $this->assertSame(0, bccomp($byAccount[self::ACCOUNT_HUTANG_KONSINYASI]->first()->debit, '8000', 4));
    }

    /**
     * Test regresi: sale TANPA item konsinyasi sama sekali tidak pernah
     * menyentuh akun 2-3000 atau membuat baris ConsignmentAccrual --
     * perilaku identik sebelum fitur ini ada. (File SaleServiceTest.php
     * yang sudah ada juga tetap hijau tanpa modifikasi -- itu bukti utama
     * non-regresi; ini cuma penegasan tambahan yang fokus ke akun 2-3000
     * secara eksplisit.)
     */
    public function test_an_ordinary_sale_without_consignment_items_never_touches_hutang_konsinyasi(): void
    {
        [$item, $product] = $this->makeOwnedProduct('6000');
        $this->inventory->recordInbound($item, $this->warehouse, 10, '3000', $this->makeOpeningBalanceSource(), '2026-07-01');

        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 6000]],
        ]);

        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();
        $byAccount = $this->journalLinesByAccountCode($journal);

        $this->assertArrayNotHasKey(self::ACCOUNT_HUTANG_KONSINYASI, $byAccount->all());
        $this->assertSame(0, bccomp($byAccount[self::ACCOUNT_PERSEDIAAN]->first()->credit, '3000', 4));
        $this->assertSame(0, ConsignmentAccrual::count());
    }
}
