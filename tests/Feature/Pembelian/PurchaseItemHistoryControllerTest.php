<?php

namespace Tests\Feature\Pembelian;

use App\Models\Account;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Permission;
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

class PurchaseItemHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $purchases;

    private Warehouse $warehouse;

    private Uom $pcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $this->warehouse = Warehouse::first();
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

    private function makeItem(string $sku, string $name, ?ItemCategory $category = null, ?Uom $base = null, ?Uom $purchase = null): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $name,
            'costing_type' => 'stocked',
            'base_uom_id' => ($base ?? $this->pcs)->id,
            'purchase_uom_id' => ($purchase ?? $this->pcs)->id,
            'standard_cost' => 0,
            'inventory_account_id' => Account::where('code', '1-1200')->firstOrFail()->id,
            'item_category_id' => $category?->id,
        ]);
    }

    /**
     * Buat PO satu baris lalu langsung diterima penuh.
     */
    private function purchase(Supplier $supplier, Item $item, float $qty, float $price, string $date, string $paymentMethod = 'cash', ?Uom $uom = null): int
    {
        $po = $this->purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'lines' => [
                ['item_id' => $item->id, 'qty' => $qty, 'purchase_uom_id' => ($uom ?? $this->pcs)->id, 'unit_price' => $price],
            ],
        ]);

        $this->purchases->receiveGoods($po, [$po->lines->first()->id => $qty], $date, $paymentMethod);

        return $po->id;
    }

    public function test_requires_the_pembelian_manage_permission(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pembelian.item-history.index'))->assertForbidden();
    }

    public function test_lists_received_lines_in_base_uom_and_recaps_per_item(): void
    {
        $this->actingAsAuthorizedUser();
        $supplier = Supplier::create(['name' => 'Toko Plastik']);
        $kg = Uom::where('code', 'KG')->firstOrFail();
        $gr = Uom::where('code', 'GR')->firstOrFail();
        $gula = $this->makeItem('GULA', 'Gula Pasir', null, $gr, $kg);
        $sedotan = $this->makeItem('0014', 'Sedotan');

        // 2 KG @ Rp15.000 -> 2000 GR @ Rp15/GR.
        $this->purchase($supplier, $gula, 2, 15000, '2026-07-10', 'cash', $kg);
        $this->purchase($supplier, $sedotan, 100, 50, '2026-07-10');
        $this->purchase($supplier, $sedotan, 200, 60, '2026-07-11', 'credit');

        $response = $this->get(route('pembelian.item-history.index', ['date_from' => '2026-07-10', 'date_to' => '2026-07-11']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pembelian/PerItem')
            ->has('rows', 3)
            ->where('rows', function ($rows) {
                $gula = collect($rows)->firstWhere('sku', 'GULA');

                return $gula['qty'] === '2000.0000'
                    && $gula['uom'] === 'GR'
                    && $gula['line_total'] === '30000.0000'
                    && $gula['date'] === '2026-07-10';
            })
            ->where('recap', function ($recap) {
                $sedotan = collect($recap)->firstWhere('sku', '0014');

                // (100×50 + 200×60) / 300 = 17000 / 300
                return $sedotan['qty'] === '300.0000'
                    && $sedotan['total'] === '17000.0000'
                    && $sedotan['average_cost'] === '56.6666'
                    && $sedotan['receipt_count'] === 2;
            })
            ->where('summary.total', '47000.0000'),
        );
    }

    public function test_filters_by_supplier_payment_method_and_category(): void
    {
        $this->actingAsAuthorizedUser();
        $kemasan = ItemCategory::create(['name' => 'Kemasan']);
        $bahan = ItemCategory::create(['name' => 'Bahan Baku']);
        $supplierA = Supplier::create(['name' => 'Supplier A']);
        $supplierB = Supplier::create(['name' => 'Supplier B']);
        $sedotan = $this->makeItem('0014', 'Sedotan', $kemasan);
        $teh = $this->makeItem('TEH', 'Teh Tubruk', $bahan);

        $expectedPo = $this->purchase($supplierA, $sedotan, 100, 50, '2026-07-10', 'credit');
        $this->purchase($supplierA, $sedotan, 100, 50, '2026-07-10', 'cash');
        $this->purchase($supplierB, $sedotan, 100, 50, '2026-07-10', 'credit');
        $this->purchase($supplierA, $teh, 10, 1000, '2026-07-10', 'credit');

        $response = $this->get(route('pembelian.item-history.index', [
            'date_from' => '2026-07-10',
            'date_to' => '2026-07-10',
            'supplier_id' => $supplierA->id,
            'payment_method' => 'credit',
            'item_category_id' => $kemasan->id,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.purchase_order_id', $expectedPo)
            ->where('rows.0.supplier', 'Supplier A')
            ->where('rows.0.category', 'Kemasan'),
        );
    }

    public function test_search_matches_po_number_item_name_or_sku(): void
    {
        $this->actingAsAuthorizedUser();
        $supplier = Supplier::create(['name' => 'Supplier']);
        $sedotan = $this->makeItem('0014', 'Sedotan');
        $cup = $this->makeItem('0013', 'Cup Plastik');

        $sedotanPo = $this->purchase($supplier, $sedotan, 100, 50, '2026-07-10');
        $cupPo = $this->purchase($supplier, $cup, 100, 300, '2026-07-10');

        $range = ['date_from' => '2026-07-10', 'date_to' => '2026-07-10'];

        $this->get(route('pembelian.item-history.index', [...$range, 'search' => 'sedot']))
            ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.purchase_order_id', $sedotanPo));

        $this->get(route('pembelian.item-history.index', [...$range, 'search' => '0013']))
            ->assertInertia(fn ($page) => $page
                ->where('rows', fn ($rows) => collect($rows)->pluck('sku')->contains('0013')));

        $this->get(route('pembelian.item-history.index', [...$range, 'search' => 'PO-'.$cupPo]))
            ->assertInertia(fn ($page) => $page
                ->where('rows', fn ($rows) => collect($rows)->pluck('purchase_order_id')->contains($cupPo)));
    }
}
