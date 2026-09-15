<?php

namespace Tests\Feature\Master;

use App\Models\Account;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemControllerTest extends TestCase
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

    private function baseItemPayload(array $overrides = []): array
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $persediaan = Account::where('code', '1-1200')->firstOrFail();

        return array_merge([
            'sku' => 'ITEM-UJI-'.uniqid(),
            'name' => 'Item Uji',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 1000,
            'inventory_account_id' => $persediaan->id,
            'is_active' => true,
        ], $overrides);
    }

    public function test_item_category_can_be_assigned_when_creating(): void
    {
        $this->actingAsAuthorizedUser();
        $category = ItemCategory::create(['name' => 'Bahan Baku']);

        $this->post(route('master.items.store'), $this->baseItemPayload([
            'item_category_id' => $category->id,
        ]))->assertRedirect(route('master.items.index'));

        $item = Item::where('name', 'Item Uji')->firstOrFail();
        $this->assertSame($category->id, $item->item_category_id);
    }

    public function test_item_category_is_optional_and_defaults_to_null(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.items.store'), $this->baseItemPayload())
            ->assertRedirect(route('master.items.index'));

        $item = Item::where('name', 'Item Uji')->firstOrFail();
        $this->assertNull($item->item_category_id);
    }

    public function test_item_category_can_be_changed_on_update(): void
    {
        $this->actingAsAuthorizedUser();
        $bahanBaku = ItemCategory::create(['name' => 'Bahan Baku']);
        $kemasan = ItemCategory::create(['name' => 'Kemasan']);

        $this->post(route('master.items.store'), $this->baseItemPayload([
            'item_category_id' => $bahanBaku->id,
        ]));
        $item = Item::where('name', 'Item Uji')->firstOrFail();

        $this->put(route('master.items.update', $item), $this->baseItemPayload([
            'sku' => $item->sku,
            'item_category_id' => $kemasan->id,
        ]))->assertRedirect(route('master.items.index'));

        $this->assertSame($kemasan->id, $item->fresh()->item_category_id);
    }

    public function test_a_nonexistent_item_category_id_is_rejected(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->post(route('master.items.store'), $this->baseItemPayload([
            'item_category_id' => 99999,
        ]));

        $response->assertSessionHasErrors(['item_category_id']);
        $this->assertSame(0, Item::count());
    }

    public function test_index_sorts_by_name_by_default(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.items.store'), $this->baseItemPayload(['sku' => 'SKU-Z', 'name' => 'Zebra']));
        $this->post(route('master.items.store'), $this->baseItemPayload(['sku' => 'SKU-A', 'name' => 'Apel']));
        $this->post(route('master.items.store'), $this->baseItemPayload(['sku' => 'SKU-M', 'name' => 'Mangga']));

        // SKU sengaja TIDAK urut alfabet sama dengan nama (Z, A, M) --
        // membuktikan urutan yang benar-benar dipakai adalah NAMA, bukan
        // kebetulan sama dengan urutan SKU/insert.
        $response = $this->get(route('master.items.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Master/Items/Index')
            ->where('items.data.0.name', 'Apel')
            ->where('items.data.1.name', 'Mangga')
            ->where('items.data.2.name', 'Zebra'),
        );
    }

    public function test_index_paginates_at_twenty_per_page(): void
    {
        $this->actingAsAuthorizedUser();
        for ($i = 1; $i <= 25; $i++) {
            $this->post(route('master.items.store'), $this->baseItemPayload([
                'sku' => 'SKU-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'Item '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]));
        }

        $firstPage = $this->get(route('master.items.index'));
        $firstPage->assertInertia(fn ($page) => $page
            ->has('items.data', 20)
            ->where('items.total', 25)
            ->where('items.current_page', 1)
            ->where('items.last_page', 2),
        );

        $secondPage = $this->get(route('master.items.index', ['page' => 2]));
        $secondPage->assertInertia(fn ($page) => $page
            ->has('items.data', 5)
            ->where('items.current_page', 2),
        );
    }

    public function test_index_search_filters_by_sku_or_name(): void
    {
        $this->actingAsAuthorizedUser();
        $this->post(route('master.items.store'), $this->baseItemPayload(['sku' => 'KOPI-001', 'name' => 'Kopi Arabika']));
        $this->post(route('master.items.store'), $this->baseItemPayload(['sku' => 'TEH-001', 'name' => 'Teh Hijau']));
        $this->post(route('master.items.store'), $this->baseItemPayload(['sku' => 'KOPI-002', 'name' => 'Susu UHT']));

        // Cocok lewat SKU (mengandung "KOPI").
        $bySku = $this->get(route('master.items.index', ['q' => 'KOPI']));
        $bySku->assertInertia(fn ($page) => $page
            ->has('items.data', 2)
            ->where('items.total', 2),
        );

        // Cocok lewat NAMA (mengandung "Hijau"), bukan SKU.
        $byName = $this->get(route('master.items.index', ['q' => 'Hijau']));
        $byName->assertInertia(fn ($page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.name', 'Teh Hijau'),
        );

        // Tidak cocok apa pun -> daftar kosong, bukan error.
        $noMatch = $this->get(route('master.items.index', ['q' => 'Nonexistent']));
        $noMatch->assertInertia(fn ($page) => $page->where('items.total', 0));
    }
}
