<?php

namespace Tests\Feature\Master;

use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BranchService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * "Kelola Cabang" -- Multi-Cabang Lapisan 1. Lihat rancangan yang
 * disetujui. Kasus verifikasi WAJIB dari checklist user ada di sini: CRUD
 * outlet, enforcement is_headquarters lewat BranchService (maksimal satu
 * pusat), permission branches.manage, auto-warehouse.
 */
class OutletControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    private function roleWith(array $permissionKeys): Role
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);

        $permissions = collect($permissionKeys)->map(
            fn (string $key) => Permission::create(['key' => $key, 'label' => $key, 'group' => 'Test']),
        );

        $role->permissions()->attach($permissions->pluck('id'));

        return $role;
    }

    public function test_user_without_branches_manage_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);

        $this->actingAs($user)->get('/master/outlets')->assertForbidden();
    }

    public function test_admin_can_view_the_outlets_page(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['branches.manage'])->id]);

        $response = $this->actingAs($admin)->get('/master/outlets');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Master/Outlets/Index')
            ->where('outlets.0.name', 'Outlet Pusat')
        );
    }

    // Auto-warehouse: membuat cabang baru otomatis membuat SATU warehouse
    // pendamping dalam transaksi yang sama.
    public function test_creating_an_outlet_automatically_creates_a_companion_warehouse(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['branches.manage'])->id]);

        $this->actingAs($admin)->post('/master/outlets', [
            'name' => 'Cabang Surabaya',
            'code' => 'SBY',
            'address' => 'Jl. Merdeka 1',
            'is_active' => true,
            'is_headquarters' => false,
        ])->assertRedirect(route('master.outlets.index'));

        $outlet = Outlet::where('name', 'Cabang Surabaya')->firstOrFail();
        $warehouse = Warehouse::where('outlet_id', $outlet->id)->first();

        $this->assertNotNull($warehouse);
        $this->assertSame('Gudang Cabang Surabaya', $warehouse->name);
        $this->assertFalse($outlet->is_headquarters);
    }

    public function test_admin_can_edit_an_outlet(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['branches.manage'])->id]);
        $outlet = Outlet::first();

        $this->actingAs($admin)->put("/master/outlets/{$outlet->id}", [
            'name' => 'Outlet Pusat Baru',
            'code' => 'PUSAT',
            'address' => 'Alamat baru',
            'is_active' => true,
            'is_headquarters' => true,
        ])->assertRedirect(route('master.outlets.index'));

        $outlet->refresh();
        $this->assertSame('Outlet Pusat Baru', $outlet->name);
        $this->assertSame('Alamat baru', $outlet->address);
    }

    public function test_deleting_an_outlet_with_existing_data_is_blocked_with_a_friendly_message(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['branches.manage'])->id]);
        $outlet = Outlet::first();

        $response = $this->actingAs($admin)->delete("/master/outlets/{$outlet->id}");

        $response->assertRedirect(route('master.outlets.index'));
        $response->assertSessionHas('error');
        $this->assertNotNull(Outlet::find($outlet->id), 'outlet dengan warehouse terkait tidak boleh benar-benar terhapus');
    }

    // (WAJIB) Invarian "maksimal satu pusat" -- ditegakkan BranchService,
    // bukan cuma dipercaya dari input form.
    public function test_creating_a_new_headquarters_unflags_the_previous_one(): void
    {
        $branches = app(BranchService::class);
        $original = Outlet::first();
        $this->assertTrue($original->is_headquarters);

        $newHq = $branches->createOutlet([
            'name' => 'Cabang Baru Pusat',
            'code' => 'BARU',
            'address' => null,
            'is_active' => true,
            'is_headquarters' => true,
        ]);

        $this->assertTrue($newHq->fresh()->is_headquarters);
        $this->assertFalse($original->fresh()->is_headquarters);
        $this->assertSame(
            1,
            Outlet::where('is_headquarters', true)->count(),
            'harus selalu ada TEPAT SATU headquarters, tidak boleh dua atau nol',
        );
    }

    public function test_cannot_unset_headquarters_without_designating_another_outlet_first(): void
    {
        $branches = app(BranchService::class);
        $hq = Outlet::first();
        $this->assertTrue($hq->is_headquarters);

        $this->expectException(InvalidArgumentException::class);

        $branches->updateOutlet($hq, [
            'name' => $hq->name,
            'code' => $hq->code,
            'address' => $hq->address,
            'is_active' => true,
            'is_headquarters' => false,
        ]);
    }

    public function test_web_form_surfaces_the_cannot_unset_headquarters_error_as_a_flash_message_not_a_500(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['branches.manage'])->id]);
        $hq = Outlet::first();

        $response = $this->actingAs($admin)->put("/master/outlets/{$hq->id}", [
            'name' => $hq->name,
            'code' => $hq->code,
            'address' => $hq->address,
            'is_active' => true,
            'is_headquarters' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertTrue($hq->fresh()->is_headquarters, 'status pusat tidak boleh berubah kalau ditolak');
    }

    public function test_setHeadquarters_directly_moves_status_atomically(): void
    {
        $branches = app(BranchService::class);
        $original = Outlet::first();
        $second = Outlet::create(['name' => 'Cabang Kedua', 'is_active' => true]);

        $branches->setHeadquarters($second);

        $this->assertTrue($second->fresh()->is_headquarters);
        $this->assertFalse($original->fresh()->is_headquarters);
    }
}
