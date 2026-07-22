<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private Warehouse $warehouse;

    private Uom $pcs;

    private Account $persediaanAccount;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->warehouse = Warehouse::first();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();
    }

    public function test_user_without_laporan_view_permission_gets_403(): void
    {
        $user = $this->userWithPermission('kasir.access');

        $this->actingAs($user)->get('/laporan/stok')->assertForbidden();
    }

    public function test_stocked_item_shows_current_stock_and_average_cost(): void
    {
        $user = $this->userWithPermission('laporan.view');
        $item = $this->makeStockedItem('WIDGET');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, $this->makeSource(), '2026-07-01');
        $this->inventory->recordInbound($item, $this->warehouse, 100, 2000, $this->makeSource(), '2026-07-02');

        $response = $this->actingAs($user)->get('/laporan/stok?warehouse_id='.$this->warehouse->id);
        $response->assertOk();

        $row = $this->rowFor($response, $item->id);

        $this->assertSame(0, bccomp($row['stock'], '200', 4));
        $this->assertSame(0, bccomp($row['average_cost'], '1500', 4));
        $this->assertSame(0, bccomp($row['inventory_value'], '300000', 4));
    }

    public function test_stocked_item_with_no_movements_shows_zero(): void
    {
        $user = $this->userWithPermission('laporan.view');
        $item = $this->makeStockedItem('NOMOVE');

        $response = $this->actingAs($user)->get('/laporan/stok?warehouse_id='.$this->warehouse->id);
        $row = $this->rowFor($response, $item->id);

        $this->assertSame(0, bccomp($row['stock'], '0', 4));
        $this->assertSame(0, bccomp($row['average_cost'], '0', 4));
        $this->assertSame(0, bccomp($row['inventory_value'], '0', 4));
    }

    public function test_cost_only_item_shows_null_stock_and_standard_cost_as_average(): void
    {
        $user = $this->userWithPermission('laporan.view');
        $item = $this->makeCostOnlyItem('WATER', '500');

        $response = $this->actingAs($user)->get('/laporan/stok');
        $row = $this->rowFor($response, $item->id);

        $this->assertNull($row['stock']);
        $this->assertNull($row['inventory_value']);
        $this->assertSame(0, bccomp($row['average_cost'], '500', 4));
    }

    public function test_stock_from_a_different_warehouse_is_not_mixed_in(): void
    {
        $user = $this->userWithPermission('laporan.view');
        $item = $this->makeStockedItem('MULTI-WH');
        $otherWarehouse = Warehouse::create(['outlet_id' => $this->warehouse->outlet_id, 'name' => 'Gudang Lain']);

        $this->inventory->recordInbound($item, $this->warehouse, 50, 1000, $this->makeSource(), '2026-07-01');
        $this->inventory->recordInbound($item, $otherWarehouse, 999, 1, $this->makeSource(), '2026-07-01');

        $response = $this->actingAs($user)->get('/laporan/stok?warehouse_id='.$this->warehouse->id);
        $row = $this->rowFor($response, $item->id);

        $this->assertSame(0, bccomp($row['stock'], '50', 4));
    }

    public function test_query_count_does_not_scale_with_item_count(): void
    {
        $user = $this->userWithPermission('laporan.view');

        foreach (range(1, 3) as $i) {
            $item = $this->makeStockedItem('SMALL-'.$i);
            $this->inventory->recordInbound($item, $this->warehouse, 10, 100, $this->makeSource(), '2026-07-01');
        }

        DB::enableQueryLog();
        $this->actingAs($user)->get('/laporan/stok?warehouse_id='.$this->warehouse->id)->assertOk();
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        foreach (range(1, 20) as $i) {
            $item = $this->makeStockedItem('BIG-'.$i);
            $this->inventory->recordInbound($item, $this->warehouse, 10, 100, $this->makeSource(), '2026-07-01');
        }

        DB::enableQueryLog();
        $this->actingAs($user)->get('/laporan/stok?warehouse_id='.$this->warehouse->id)->assertOk();
        $bigCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($smallCount, $bigCount, 'Query count must stay constant regardless of item count (no N+1).');
    }

    private function rowFor($response, int $itemId): array
    {
        $items = $response->viewData('page')['props']['items'];

        foreach ($items as $item) {
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

    private function makeStockedItem(string $sku): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode($sku),
            'name' => $sku,
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function makeCostOnlyItem(string $sku, string $standardCost): Item
    {
        return Item::create([
            'sku' => $this->uniqueCode($sku),
            'name' => $sku,
            'costing_type' => 'cost_only',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => $standardCost,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);
    }

    private function makeSource(): Outlet
    {
        return Outlet::create(['name' => 'Source '.(++self::$seq)]);
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix.'-'.(++self::$seq);
    }
}
