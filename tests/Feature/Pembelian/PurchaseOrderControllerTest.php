<?php

namespace Tests\Feature\Pembelian;

use App\Models\Account;
use App\Models\Item;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\PurchaseService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
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

    private function makePo(string $supplierName, string $date): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => $supplierName]);

        return PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => $date,
            'status' => 'open',
            'subtotal' => '0',
            'tax_total' => '0',
            'grand_total' => '0',
        ]);
    }

    public function test_index_defaults_to_todays_date_range(): void
    {
        $this->actingAsAuthorizedUser();
        $today = $this->makePo('Supplier Hari Ini', now()->toDateString());
        $yesterday = $this->makePo('Supplier Kemarin', now()->subDay()->toDateString());

        $response = $this->get(route('pembelian.purchase-orders.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/PurchaseOrders/Index')
            ->where('filters.date_from', now()->toDateString())
            ->where('filters.date_to', now()->toDateString())
            ->where('purchaseOrders', fn ($pos) => collect($pos)->pluck('id')->contains($today->id)
                && ! collect($pos)->pluck('id')->contains($yesterday->id)),
        );
    }

    public function test_index_filters_by_explicit_date_range(): void
    {
        $this->actingAsAuthorizedUser();
        $inRange = $this->makePo('Supplier A', '2026-07-10');
        $outOfRange = $this->makePo('Supplier B', '2026-07-01');

        $response = $this->get(route('pembelian.purchase-orders.index', [
            'date_from' => '2026-07-05',
            'date_to' => '2026-07-15',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('purchaseOrders', fn ($pos) => collect($pos)->pluck('id')->contains($inRange->id)
                && ! collect($pos)->pluck('id')->contains($outOfRange->id)),
        );
    }

    public function test_index_searches_by_supplier_name(): void
    {
        $this->actingAsAuthorizedUser();
        $match = $this->makePo('Toko Maju Jaya', '2026-07-10');
        $noMatch = $this->makePo('Toko Lain', '2026-07-10');

        $response = $this->get(route('pembelian.purchase-orders.index', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'search' => 'maju',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('purchaseOrders', fn ($pos) => collect($pos)->pluck('id')->contains($match->id)
                && ! collect($pos)->pluck('id')->contains($noMatch->id)),
        );
    }

    public function test_index_searches_by_po_number(): void
    {
        $this->actingAsAuthorizedUser();
        $po = $this->makePo('Supplier C', '2026-07-10');
        $this->makePo('Supplier D', '2026-07-10');

        $response = $this->get(route('pembelian.purchase-orders.index', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'search' => "PO-{$po->id}",
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('purchaseOrders', fn ($pos) => collect($pos)->pluck('id')->contains($po->id)),
        );
    }

    public function test_create_page_does_not_ship_the_full_item_or_supplier_catalog(): void
    {
        $this->actingAsAuthorizedUser();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        Item::create([
            'sku' => 'ITEM-1', 'name' => 'Item 1', 'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id, 'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0, 'inventory_account_id' => $account->id,
        ]);
        Supplier::create(['name' => 'Supplier Test']);

        $response = $this->get(route('pembelian.purchase-orders.create'));

        $response->assertOk();
        // Item & supplier dipilih lewat Item/SupplierCombobox
        // (search-as-you-type), bukan dropdown yang memuat seluruh katalog —
        // halaman ini seharusnya tidak lagi membawa daftar penuh keduanya.
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/PurchaseOrders/Create')
            ->missing('items')
            ->missing('suppliers')
            ->has('warehouses')
            ->has('uoms')
            ->has('taxRates'),
        );
    }

    public function test_store_creates_a_purchase_order_using_an_item_found_via_search(): void
    {
        $this->actingAsAuthorizedUser();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $item = Item::create([
            'sku' => 'ITEM-2', 'name' => 'Item 2', 'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id, 'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0, 'inventory_account_id' => $account->id,
        ]);
        $supplier = Supplier::create(['name' => 'Supplier E']);

        $response = $this->post(route('pembelian.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => '2026-07-17',
            'lines' => [
                ['item_id' => $item->id, 'purchase_uom_id' => $pcs->id, 'qty' => 10, 'unit_price' => 1000],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_store_saves_notes_and_show_page_displays_them(): void
    {
        $this->actingAsAuthorizedUser();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $item = Item::create([
            'sku' => 'ITEM-NOTES', 'name' => 'Item Notes', 'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id, 'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0, 'inventory_account_id' => $account->id,
        ]);
        $supplier = Supplier::create(['name' => 'Supplier Notes']);

        $response = $this->post(route('pembelian.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => '2026-07-17',
            'lines' => [
                ['item_id' => $item->id, 'purchase_uom_id' => $pcs->id, 'qty' => 10, 'unit_price' => 1000],
            ],
            'notes' => 'Supplier janji diskon 5% untuk PO berikutnya.',
        ]);

        $response->assertRedirect();
        $po = PurchaseOrder::firstOrFail();
        $this->assertSame('Supplier janji diskon 5% untuk PO berikutnya.', $po->notes);

        $showResponse = $this->get(route('pembelian.purchase-orders.show', $po));
        $showResponse->assertInertia(fn ($page) => $page
            ->component('Pembelian/PurchaseOrders/Show')
            ->where('purchaseOrder.notes', 'Supplier janji diskon 5% untuk PO berikutnya.'),
        );
    }

    public function test_notes_are_optional_and_default_to_null(): void
    {
        $this->actingAsAuthorizedUser();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $item = Item::create([
            'sku' => 'ITEM-NO-NOTES', 'name' => 'Item No Notes', 'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id, 'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0, 'inventory_account_id' => $account->id,
        ]);
        $supplier = Supplier::create(['name' => 'Supplier No Notes']);

        $response = $this->post(route('pembelian.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => '2026-07-17',
            'lines' => [
                ['item_id' => $item->id, 'purchase_uom_id' => $pcs->id, 'qty' => 10, 'unit_price' => 1000],
            ],
        ]);

        $response->assertRedirect();
        $this->assertNull(PurchaseOrder::firstOrFail()->notes);
    }

    public function test_show_page_includes_nota_status_badges_per_receipt(): void
    {
        $this->actingAsAuthorizedUser();
        $supplier = Supplier::create(['name' => 'Supplier Badge']);
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $item = Item::create([
            'sku' => 'ITEM-BADGE', 'name' => 'Item Badge', 'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id, 'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0, 'inventory_account_id' => $account->id,
        ]);

        $purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $po = $purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 20, 'purchase_uom_id' => $pcs->id, 'unit_price' => 5000],
            ],
        ]);
        $poLine = $po->lines->first();
        // Terima 10 tunai, 10 kredit -> dua nota dengan status beda.
        $purchases->receiveGoods($po, [$poLine->id => 10], '2026-07-01', 'cash');
        $purchases->receiveGoods($po, [$poLine->id => 10], '2026-07-02', 'credit');

        $response = $this->get(route('pembelian.purchase-orders.show', $po));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/PurchaseOrders/Show')
            ->where('receipts', fn ($receipts) => collect($receipts)->pluck('nota_status.status')->sort()->values()->all() === ['belum', 'tunai']),
        );
    }

    public function test_index_page_includes_receipt_badges_grouped_by_po(): void
    {
        $this->actingAsAuthorizedUser();
        $supplier = Supplier::create(['name' => 'Supplier Badge Index']);
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();
        $item = Item::create([
            'sku' => 'ITEM-BADGE-2', 'name' => 'Item Badge 2', 'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id, 'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0, 'inventory_account_id' => $account->id,
        ]);

        $purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $po = $purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => now()->toDateString(),
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $pcs->id, 'unit_price' => 5000],
            ],
        ]);
        $poLine = $po->lines->first();
        $purchases->receiveGoods($po, [$poLine->id => 10], now()->toDateString(), 'credit');

        $response = $this->get(route('pembelian.purchase-orders.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/PurchaseOrders/Index')
            ->has("receiptBadgesByPo.{$po->id}", 1)
            ->where("receiptBadgesByPo.{$po->id}.0.status", 'belum'),
        );
    }

    public function test_unauthorized_user_cannot_access_purchase_order_index(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)
            ->get(route('pembelian.purchase-orders.index'))
            ->assertForbidden();
    }
}
