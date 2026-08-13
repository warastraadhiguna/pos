<?php

namespace Tests\Feature\Master;

use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
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

class TableControllerTest extends TestCase
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
        $role->permissions()->attach(
            Permission::create(['key' => 'master-data.manage', 'label' => 'master-data.manage', 'group' => 'Test'])->id,
        );
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_table_can_be_created(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.tables.store'), [
            'name' => 'Meja 1',
            'capacity' => 4,
            'area' => 'indoor',
            'is_active' => true,
        ])->assertRedirect(route('master.tables.index'));

        $table = DiningTable::where('name', 'Meja 1')->firstOrFail();
        $this->assertSame(4, $table->capacity);
        $this->assertSame('indoor', $table->area);
        $this->assertTrue($table->is_active);
    }

    public function test_table_capacity_and_area_are_optional(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.tables.store'), [
            'name' => 'Meja Tambahan',
            'is_active' => true,
        ])->assertRedirect(route('master.tables.index'));

        $table = DiningTable::where('name', 'Meja Tambahan')->firstOrFail();
        $this->assertNull($table->capacity);
        $this->assertNull($table->area);
    }

    public function test_table_can_be_updated(): void
    {
        $this->actingAsAuthorizedUser();
        $table = DiningTable::create(['name' => 'Meja Lama', 'capacity' => 2]);

        $this->put(route('master.tables.update', $table), [
            'name' => 'Meja Baru',
            'capacity' => 6,
            'is_active' => true,
        ])->assertRedirect(route('master.tables.index'));

        $this->assertSame('Meja Baru', $table->fresh()->name);
        $this->assertSame(6, $table->fresh()->capacity);
    }

    public function test_table_can_be_deactivated_via_the_edit_form(): void
    {
        $this->actingAsAuthorizedUser();
        $table = DiningTable::create(['name' => 'Meja Aktif', 'is_active' => true]);

        $this->put(route('master.tables.update', $table), [
            'name' => 'Meja Aktif',
            'is_active' => false,
        ])->assertRedirect(route('master.tables.index'));

        $this->assertFalse($table->fresh()->is_active);
    }

    public function test_table_not_used_in_any_sale_can_be_deleted(): void
    {
        $this->actingAsAuthorizedUser();
        $table = DiningTable::create(['name' => 'Meja Belum Dipakai']);

        $this->delete(route('master.tables.destroy', $table))
            ->assertRedirect(route('master.tables.index'));

        $this->assertSame(0, DiningTable::count());
    }

    /**
     * restrictOnDelete on sales.table_id -- a table already referenced by
     * a real sale must never be hard-deleted (would corrupt the historical
     * link used for the upcoming draft feature). The admin must
     * nonaktifkan instead, exactly like Member/Product/Item.
     */
    public function test_table_used_in_a_sale_cannot_be_deleted(): void
    {
        $this->actingAsAuthorizedUser();
        $table = DiningTable::create(['name' => 'Meja Sudah Transaksi']);

        $outlet = Outlet::first();
        $warehouse = Warehouse::first();
        $product = Product::create(['name' => 'Produk Uji', 'sell_price' => 10000]);

        $sales = new SaleService(new InventoryService(), new PostingService(), new CashAccountService(), new DraftSyncService(new BranchService()));
        $sales->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-07-10',
            'table_id' => $table->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $response = $this->delete(route('master.tables.destroy', $table));

        $response->assertRedirect(route('master.tables.index'));
        $response->assertSessionHas('error');
        $this->assertSame(1, DiningTable::count(), 'Meja masih ada -- delete harus diblokir oleh restrictOnDelete.');
    }


    public function test_unauthorized_user_cannot_manage_tables(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('master.tables.index'))->assertForbidden();
    }
}
