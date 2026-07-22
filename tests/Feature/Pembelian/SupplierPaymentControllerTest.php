<?php

namespace Tests\Feature\Pembelian;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use App\Services\SupplierPayableReportService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Supplier $supplier;

    private PurchaseService $purchases;

    private Uom $pcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->outlet = Outlet::first();
        $this->supplier = Supplier::create(['name' => 'Supplier Test']);
        $this->purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
    }

    private function actingAsAuthorizedUser(): User
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'pembelian.manage', 'label' => 'Pembelian', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    private function makeItem(string $sku): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $sku,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => Account::where('code', '1-1200')->firstOrFail()->id,
        ]);
    }

    private function receiveOnCredit(string $sku, string $qty, string $unitPrice): int
    {
        $item = $this->makeItem($sku);
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => $qty, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => $unitPrice],
            ],
        ]);
        $poLine = $po->lines->first();

        return $this->purchases->receiveGoods($po, [$poLine->id => $qty], '2026-07-01', 'credit')->id;
    }

    private function makePayment(Supplier $supplier, string $date, ?string $memo = null): SupplierPayment
    {
        $item = $this->makeItem('ITEM-'.uniqid());
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => $date,
            'lines' => [
                ['item_id' => $item->id, 'qty' => '10', 'purchase_uom_id' => $this->pcs->id, 'unit_price' => '10000'],
            ],
        ]);
        $poLine = $po->lines->first();
        $receipt = $this->purchases->receiveGoods($po, [$poLine->id => '10'], $date, 'credit');

        $this->post(route('pembelian.supplier-payments.store'), [
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $supplier->id,
            'date' => $date,
            'amount' => 100000,
            'memo' => $memo,
            'allocations' => [
                ['goods_receipt_id' => $receipt->id, 'amount' => 100000],
            ],
        ]);

        return SupplierPayment::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
    }

    public function test_index_defaults_to_todays_date_range(): void
    {
        $this->actingAsAuthorizedUser();
        $today = $this->makePayment($this->supplier, now()->toDateString());
        $yesterdaySupplier = Supplier::create(['name' => 'Supplier Kemarin']);
        $yesterday = $this->makePayment($yesterdaySupplier, now()->subDay()->toDateString());

        $response = $this->get(route('pembelian.supplier-payments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/SupplierPayments/Index')
            ->where('filters.date_from', now()->toDateString())
            ->where('filters.date_to', now()->toDateString())
            ->where('payments', fn ($payments) => collect($payments)->pluck('id')->contains($today->id)
                && ! collect($payments)->pluck('id')->contains($yesterday->id)),
        );
    }

    public function test_index_filters_by_explicit_date_range(): void
    {
        $this->actingAsAuthorizedUser();
        $inRangeSupplier = Supplier::create(['name' => 'Supplier A']);
        $inRange = $this->makePayment($inRangeSupplier, '2026-07-10');
        $outOfRangeSupplier = Supplier::create(['name' => 'Supplier B']);
        $outOfRange = $this->makePayment($outOfRangeSupplier, '2026-07-01');

        $response = $this->get(route('pembelian.supplier-payments.index', [
            'date_from' => '2026-07-05',
            'date_to' => '2026-07-15',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('payments', fn ($payments) => collect($payments)->pluck('id')->contains($inRange->id)
                && ! collect($payments)->pluck('id')->contains($outOfRange->id)),
        );
    }

    public function test_index_searches_by_supplier_name(): void
    {
        $this->actingAsAuthorizedUser();
        $matchSupplier = Supplier::create(['name' => 'Toko Maju Jaya']);
        $match = $this->makePayment($matchSupplier, '2026-07-10');
        $noMatchSupplier = Supplier::create(['name' => 'Toko Lain']);
        $noMatch = $this->makePayment($noMatchSupplier, '2026-07-10');

        $response = $this->get(route('pembelian.supplier-payments.index', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'search' => 'maju',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('payments', fn ($payments) => collect($payments)->pluck('id')->contains($match->id)
                && ! collect($payments)->pluck('id')->contains($noMatch->id)),
        );
    }

    public function test_index_searches_by_memo(): void
    {
        $this->actingAsAuthorizedUser();
        $match = $this->makePayment($this->supplier, '2026-07-10', 'Pelunasan termin 1');
        $secondSupplier = Supplier::create(['name' => 'Supplier Lain']);
        $noMatch = $this->makePayment($secondSupplier, '2026-07-10', 'Bayar rutin');

        $response = $this->get(route('pembelian.supplier-payments.index', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'search' => 'termin',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('payments', fn ($payments) => collect($payments)->pluck('id')->contains($match->id)
                && ! collect($payments)->pluck('id')->contains($noMatch->id)),
        );
    }

    public function test_store_records_a_payment_with_allocations_and_redirects_with_success(): void
    {
        $this->actingAsAuthorizedUser();
        $receiptId = $this->receiveOnCredit('ITEM-1', '10', '25000');

        $response = $this->post(route('pembelian.supplier-payments.store'), [
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'memo' => 'Bayar sebagian',
            'allocations' => [
                ['goods_receipt_id' => $receiptId, 'amount' => 250000],
            ],
        ]);

        $response->assertRedirect(route('pembelian.supplier-payments.index'));
        $response->assertSessionHas('success');
        $this->assertSame(1, SupplierPayment::count());
        $this->assertSame(0, bccomp(SupplierPayment::first()->amount, '250000', 4));
        $this->assertSame(1, SupplierPayment::first()->allocations()->count());
        $this->assertSame('1-1000', SupplierPayment::first()->cash_account_code);
    }

    public function test_store_with_bank_selected_stores_the_bank_code(): void
    {
        $this->actingAsAuthorizedUser();
        $receiptId = $this->receiveOnCredit('ITEM-BANK', '10', '25000');

        $this->post(route('pembelian.supplier-payments.store'), [
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'cash_account_code' => '1-1100',
            'allocations' => [
                ['goods_receipt_id' => $receiptId, 'amount' => 250000],
            ],
        ]);

        $this->assertSame('1-1100', SupplierPayment::first()->cash_account_code);
    }

    public function test_create_page_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('pembelian.supplier-payments.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/SupplierPayments/Create')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_store_requires_amount_supplier_and_allocations(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('pembelian.supplier-payments.store'), [
            'outlet_id' => $this->outlet->id,
            'date' => '2026-07-10',
        ]);

        $response->assertSessionHasErrors(['supplier_id', 'amount', 'allocations']);
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_store_rejects_a_zero_amount(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('pembelian.supplier-payments.store'), [
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 0,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_store_rejects_allocations_that_do_not_sum_to_the_amount(): void
    {
        $this->actingAsAuthorizedUser();
        $receiptId = $this->receiveOnCredit('ITEM-2', '10', '25000');

        $response = $this->post(route('pembelian.supplier-payments.store'), [
            'outlet_id' => $this->outlet->id,
            'supplier_id' => $this->supplier->id,
            'date' => '2026-07-10',
            'amount' => 250000,
            'allocations' => [
                ['goods_receipt_id' => $receiptId, 'amount' => 200000],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_summary_endpoint_returns_nota_breakdown_matching_the_report_service(): void
    {
        $this->actingAsAuthorizedUser();
        $this->receiveOnCredit('ITEM-SUM', '10', '10000');

        $response = $this->getJson(route('pembelian.supplier-payments.summary', ['supplier_id' => $this->supplier->id]));

        $response->assertOk();
        $expected = (new SupplierPayableReportService())->outstandingForSupplier($this->supplier->id);
        $this->assertSame(0, bccomp($response->json('outstanding'), $expected, 4));
        $this->assertCount(1, $response->json('notas'));
        $this->assertSame('belum', $response->json('notas.0.status'));
    }

    public function test_fifo_preview_endpoint_matches_the_service_fifo_algorithm(): void
    {
        $this->actingAsAuthorizedUser();
        $olderReceiptId = $this->receiveOnCredit('ITEM-FIFO-1', '10', '10000'); // 100,000, dated 2026-07-01

        $response = $this->getJson(route('pembelian.supplier-payments.fifo-preview', [
            'supplier_id' => $this->supplier->id,
            'amount' => 60000,
        ]));

        $response->assertOk();
        $response->assertJson([
            'allocations' => [
                ['goods_receipt_id' => $olderReceiptId, 'amount' => '60000.0000'],
            ],
        ]);
    }

    public function test_create_page_resolves_initial_supplier_and_goods_receipt_from_query_params(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('pembelian.supplier-payments.create', [
            'supplier_id' => $this->supplier->id,
            'goods_receipt_id' => 42,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/SupplierPayments/Create')
            ->where('initialSupplier.id', $this->supplier->id)
            ->where('initialGoodsReceiptId', 42),
        );
    }

    public function test_create_page_has_no_initial_supplier_without_query_param(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get(route('pembelian.supplier-payments.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/SupplierPayments/Create')
            ->where('initialSupplier', null)
            ->where('initialGoodsReceiptId', null),
        );
    }

    public function test_unauthorized_user_cannot_access_supplier_payment_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('pembelian.supplier-payments.index'))->assertForbidden();
        $this->actingAs($user)->post(route('pembelian.supplier-payments.store'), [])->assertForbidden();
    }
}
