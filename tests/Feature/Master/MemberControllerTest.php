<?php

namespace Tests\Feature\Master;

use App\Models\Member;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\DraftSyncService;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberControllerTest extends TestCase
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

    public function test_member_can_be_created(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.members.store'), [
            'name' => 'Budi Santoso',
            'phone' => '0812-1111-2222',
            'email' => 'budi@example.com',
            'address' => 'Jl. Merdeka No. 1',
            'is_active' => true,
        ])->assertRedirect(route('master.members.index'));

        $member = Member::where('name', 'Budi Santoso')->firstOrFail();
        $this->assertSame('0812-1111-2222', $member->phone);
        $this->assertSame('budi@example.com', $member->email);
        $this->assertTrue($member->is_active);
    }

    public function test_member_phone_email_and_address_are_optional(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.members.store'), [
            'name' => 'Pelanggan Tanpa Kontak',
            'is_active' => true,
        ])->assertRedirect(route('master.members.index'));

        $member = Member::where('name', 'Pelanggan Tanpa Kontak')->firstOrFail();
        $this->assertNull($member->phone);
        $this->assertNull($member->email);
        $this->assertNull($member->address);
    }

    public function test_member_can_be_updated(): void
    {
        $this->actingAsAuthorizedUser();
        $member = Member::create(['name' => 'Nama Lama', 'phone' => '0811']);

        $this->put(route('master.members.update', $member), [
            'name' => 'Nama Baru',
            'phone' => '0822',
            'is_active' => true,
        ])->assertRedirect(route('master.members.index'));

        $this->assertSame('Nama Baru', $member->fresh()->name);
        $this->assertSame('0822', $member->fresh()->phone);
    }

    public function test_member_can_be_deactivated_via_the_edit_form(): void
    {
        $this->actingAsAuthorizedUser();
        $member = Member::create(['name' => 'Member Aktif', 'is_active' => true]);

        $this->put(route('master.members.update', $member), [
            'name' => 'Member Aktif',
            'is_active' => false,
        ])->assertRedirect(route('master.members.index'));

        $this->assertFalse($member->fresh()->is_active);
    }

    public function test_member_not_used_in_any_sale_can_be_deleted(): void
    {
        $this->actingAsAuthorizedUser();
        $member = Member::create(['name' => 'Member Belum Dipakai']);

        $this->delete(route('master.members.destroy', $member))
            ->assertRedirect(route('master.members.index'));

        $this->assertSame(0, Member::count());
    }

    /**
     * restrictOnDelete on sales.member_id -- a member already referenced by
     * a real sale must never be hard-deleted (would corrupt/undermine the
     * historical link used for future per-member reporting and the
     * upcoming draft feature). The admin must nonaktifkan instead, exactly
     * like Product/Item.
     */
    public function test_member_used_in_a_sale_cannot_be_deleted(): void
    {
        $this->actingAsAuthorizedUser();
        $member = Member::create(['name' => 'Member Sudah Transaksi']);

        $outlet = Outlet::first();
        $warehouse = Warehouse::first();
        $product = Product::create(['name' => 'Produk Uji', 'sell_price' => 10000]);

        $sales = new SaleService(new InventoryService(), new PostingService(), new CashAccountService(), new DraftSyncService());
        $sales->createSale([
            'outlet_id' => $outlet->id,
            'warehouse_id' => $warehouse->id,
            'date' => '2026-07-10',
            'member_id' => $member->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        $response = $this->delete(route('master.members.destroy', $member));

        $response->assertRedirect(route('master.members.index'));
        $response->assertSessionHas('error');
        $this->assertSame(1, Member::count(), 'Member masih ada -- delete harus diblokir oleh restrictOnDelete.');
    }

    public function test_search_only_returns_active_members_matching_the_query(): void
    {
        $this->actingAsAuthorizedUser();
        Member::create(['name' => 'Budi Santoso', 'is_active' => true]);
        Member::create(['name' => 'Budiman Nonaktif', 'is_active' => false]);
        Member::create(['name' => 'Citra Lestari', 'is_active' => true]);

        $response = $this->getJson(route('master.members.search', ['q' => 'Budi']));

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Budi Santoso'));
        $this->assertFalse($names->contains('Budiman Nonaktif'), 'Member nonaktif tidak boleh muncul di picker checkout.');
        $this->assertFalse($names->contains('Citra Lestari'));
    }

    public function test_unauthorized_user_cannot_manage_members(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('master.members.index'))->assertForbidden();
    }
}
