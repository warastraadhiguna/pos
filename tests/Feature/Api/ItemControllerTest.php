<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemControllerTest extends TestCase
{
    use RefreshDatabase;

    private Uom $pcs;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->account = Account::where('code', '1-1200')->firstOrFail();
    }

    public function test_returns_item_master_fields_without_stock(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->makeItem('BAHAN-01', 'Bahan Satu');

        $response = $this->getJson('/api/v1/items');

        $response->assertOk();
        $response->assertJsonPath('data.0.sku', 'BAHAN-01');
        $response->assertJsonPath('data.0.base_uom.code', 'PCS');
        $response->assertJsonMissingPath('data.0.stock');
        $response->assertJsonStructure(['meta' => ['synced_at']]);
    }

    public function test_updated_since_only_returns_items_changed_after_the_watermark(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $old = $this->makeItem('LAMA', 'Item Lama');
        DB::table('items')->where('id', $old->id)->update(['updated_at' => Carbon::parse('2020-01-01')]);

        $watermark = Carbon::now()->subMinute();
        $this->makeItem('BARU', 'Item Baru');

        $response = $this->getJson('/api/v1/items?'.http_build_query(['updated_since' => $watermark->toIso8601String()]));

        $response->assertOk();
        $skus = collect($response->json('data'))->pluck('sku');
        $this->assertTrue($skus->contains('BARU'));
        $this->assertFalse($skus->contains('LAMA'));
    }

    public function test_stock_snapshot_reflects_recorded_inbound_and_costonly_items_show_null_stock(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $warehouse = Warehouse::first();
        $stockedItem = $this->makeItem('STOK-01', 'Item Stok', 'stocked');
        $costOnlyItem = $this->makeItem('AIR-01', 'Air', 'cost_only', standardCost: '500');

        (new InventoryService())->recordInbound($stockedItem, $warehouse, 50, 1000, Outlet::first(), '2026-07-01');

        $response = $this->getJson('/api/v1/items/stock?warehouse_id='.$warehouse->id);

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('item_id');

        $this->assertSame('50.0000', $rows[$stockedItem->id]['stock']);
        $this->assertSame('1000.0000', $rows[$stockedItem->id]['average_cost']);
        $this->assertNull($rows[$costOnlyItem->id]['stock']);
        $this->assertSame('500.0000', $rows[$costOnlyItem->id]['average_cost']);
        $response->assertJsonStructure(['meta' => ['as_of']]);
    }

    public function test_stock_snapshot_does_not_scale_queries_with_item_count(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        foreach (range(1, 5) as $i) {
            $this->makeItem('SMALL-'.$i, 'Small '.$i);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/items/stock')->assertOk();
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        foreach (range(1, 20) as $i) {
            $this->makeItem('BIG-'.$i, 'Big '.$i);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/items/stock')->assertOk();
        $bigCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($smallCount, $bigCount, 'Query count must stay constant regardless of item count (no N+1).');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/items')->assertStatus(401);
        $this->getJson('/api/v1/items/stock')->assertStatus(401);
    }

    private function makeItem(string $sku, string $name, string $costingType = 'stocked', string $standardCost = '0'): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $name,
            'costing_type' => $costingType,
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => $standardCost,
            'inventory_account_id' => $this->account->id,
        ]);
    }
}
