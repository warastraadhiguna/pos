<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
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

class SupplierPayableReportControllerTest extends TestCase
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
        $permission = Permission::create(['key' => 'laporan.view', 'label' => 'Laporan', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_index_shows_outstanding_balance_per_supplier(): void
    {
        $this->actingAsAuthorizedUser();

        $supplier = Supplier::create(['name' => 'Supplier Laporan']);
        $purchases = new PurchaseService(new InventoryService(), new PostingService(), new CashAccountService());
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $item = Item::create([
            'sku' => 'ITEM-LAP',
            'name' => 'Item Laporan',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => Account::where('code', '1-1200')->firstOrFail()->id,
        ]);
        $po = $purchases->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::first()->id,
            'date' => '2026-07-01',
            'lines' => [
                ['item_id' => $item->id, 'qty' => 10, 'purchase_uom_id' => $pcs->id, 'unit_price' => 50000],
            ],
        ]);
        $poLine = $po->lines->first();
        $purchases->receiveGoods($po, [$poLine->id => 10], '2026-07-01', 'credit');

        $response = $this->get(route('laporan.hutang', ['as_of' => '2026-07-31']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/SupplierPayable/Index')
            ->where('total', fn ($total) => bccomp($total, '500000', 4) === 0)
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('supplier_id', $supplier->id)['outstanding'] === '500000.0000'),
        );
    }

    public function test_unauthorized_user_cannot_access_the_payable_report(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('laporan.hutang'))->assertForbidden();
    }
}
