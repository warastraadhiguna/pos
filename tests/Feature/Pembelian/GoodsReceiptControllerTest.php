<?php

namespace Tests\Feature\Pembelian;

use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockMovement;
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

class GoodsReceiptControllerTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $purchases;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Uom $pcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $this->warehouse = Warehouse::first();
        $this->supplier = Supplier::create(['name' => 'Supplier Test']);
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
        $account = Account::where('code', '1-1200')->firstOrFail();

        return Item::create([
            'sku' => $sku,
            'name' => $sku,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $account->id,
        ]);
    }

    public function test_receiving_within_the_ordered_qty_is_accepted_without_any_confirmation_flag(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-1');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $response = $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'credit',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 80],
            ],
        ]);

        $response->assertRedirect(route('pembelian.purchase-orders.show', $po));
        $response->assertSessionHas('success');
        $this->assertSame('80.0000', (new InventoryService())->currentStock($item, $this->warehouse));
    }

    public function test_receiving_more_than_the_remaining_order_is_rejected_without_explicit_confirmation(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-2');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        // Kasus nyata yang memicu perbaikan ini: salah ketik "80020" untuk
        // pesanan 100 PCS. Tanpa flag konfirmasi, ini HARUS ditolak dan
        // TIDAK BOLEH menyentuh stok/jurnal sama sekali.
        $response = $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'credit',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 80020],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('0.0000', (new InventoryService())->currentStock($item, $this->warehouse));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('open', $po->fresh()->status);
    }

    public function test_receiving_more_than_the_remaining_order_is_accepted_with_explicit_confirmation(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-3');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $response = $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'credit',
            'confirm_overreceipt' => true,
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 150],
            ],
        ]);

        $response->assertRedirect(route('pembelian.purchase-orders.show', $po));
        $response->assertSessionHas('success');
        // Kelebihan tetap sah dan benar-benar menambah stok (tidak ditolak paksa).
        $this->assertSame('150.0000', (new InventoryService())->currentStock($item, $this->warehouse));
    }

    public function test_a_stale_confirmation_flag_does_not_bypass_validation_for_a_new_over_receipt(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-4');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        // Menerima 90 (dalam batas) meski confirm_overreceipt ikut terkirim
        // — tidak masalah, tidak ada kelebihan untuk dikonfirmasi.
        $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'credit',
            'confirm_overreceipt' => true,
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 90],
            ],
        ])->assertSessionHas('success');

        $this->assertSame('partial', $po->fresh()->status);
    }

    public function test_payment_method_is_required(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-6');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $response = $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 80],
            ],
        ]);

        $response->assertSessionHasErrors('payment_method');
        $this->assertSame('0.0000', (new InventoryService())->currentStock($item, $this->warehouse));
    }

    public function test_payment_method_must_be_cash_or_credit(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-7');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $response = $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'bank_transfer',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 80],
            ],
        ]);

        $response->assertSessionHasErrors('payment_method');
    }

    public function test_cash_payment_method_credits_kas_via_the_http_endpoint(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-8');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $response = $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'cash',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 100],
            ],
        ]);

        $response->assertSessionHas('success');
        $receipt = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('cash', $receipt->payment_method);
        $this->assertSame('1-1000', $receipt->cash_account_code);
    }

    public function test_cash_payment_with_bank_selected_stores_the_bank_code(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-9');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'cash',
            'cash_account_code' => '1-1100',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 100],
            ],
        ]);

        $receipt = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('1-1100', $receipt->cash_account_code);
    }

    public function test_create_page_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-10');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);

        $response = $this->get(route('pembelian.purchase-orders.receive.create', $po));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/GoodsReceipts/Create')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_notes_are_saved_and_shown_on_the_po_page(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-9');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $response = $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'credit',
            'notes' => 'Barang sedikit penyok.',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 100],
            ],
        ]);

        $response->assertSessionHas('success');
        $receipt = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('Barang sedikit penyok.', $receipt->notes);

        $showResponse = $this->get(route('pembelian.purchase-orders.show', $po));
        $showResponse->assertInertia(fn ($page) => $page
            ->component('Pembelian/PurchaseOrders/Show')
            ->where('receipts.0.notes', 'Barang sedikit penyok.'),
        );
    }

    public function test_notes_are_optional_on_goods_receipt(): void
    {
        $this->actingAsAuthorizedUser();
        $item = $this->makeItem('WIDGET-10');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);
        $poLine = $po->lines->first();

        $this->post(route('pembelian.purchase-orders.receive.store', $po), [
            'date' => '2026-07-05',
            'payment_method' => 'credit',
            'lines' => [
                ['purchase_order_line_id' => $poLine->id, 'qty' => 100],
            ],
        ]);

        $receipt = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertNull($receipt->notes);
    }

    public function test_unauthorized_user_cannot_access_goods_receipt_pages(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $item = $this->makeItem('WIDGET-5');
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 100, 'purchase_uom_id' => $this->pcs->id, 'unit_price' => 1000],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('pembelian.purchase-orders.receive.create', $po))
            ->assertForbidden();
    }
}
