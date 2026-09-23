<?php

namespace Tests\Feature\Kasir;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleVoidControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    private function actingAsUserWithPermissions(array $keys): User
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach(
            collect($keys)->map(fn (string $key) => Permission::create(['key' => $key, 'label' => $key, 'group' => 'Test'])->id),
        );
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    private function makeCompletedSale(): Sale
    {
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        return Sale::create([
            'outlet_id' => \App\Models\Outlet::first()->id,
            'warehouse_id' => \App\Models\Warehouse::first()->id,
            'local_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'date' => '2026-07-04',
            'occurred_at' => '2026-07-04 10:00:00',
            'payment_method' => 'cash',
            'cash_account_code' => '1-1000',
            'status' => 'completed',
            'subtotal' => 10000,
            'tax_total' => 0,
            'grand_total' => 10000,
            'cash_received' => 10000,
            'change_amount' => 0,
        ]);
    }

    public function test_user_without_penjualan_void_permission_cannot_void(): void
    {
        $this->actingAsUserWithPermissions(['penjualan.view']);
        $sale = $this->makeCompletedSale();

        $response = $this->post(route('penjualan.void', $sale->id), ['reason' => 'Coba tanpa izin']);

        $response->assertForbidden();
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_user_with_penjualan_void_permission_can_void_with_a_reason(): void
    {
        $user = $this->actingAsUserWithPermissions(['penjualan.view', 'penjualan.void']);
        $sale = $this->makeCompletedSale();

        $response = $this->post(route('penjualan.void', $sale->id), ['reason' => 'Salah input']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('void', $sale->fresh()->status);

        $saleVoid = SaleVoid::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('Salah input', $saleVoid->reason);
        $this->assertSame($user->id, $saleVoid->voided_by_user_id);
    }

    public function test_void_without_a_reason_is_rejected(): void
    {
        $this->actingAsUserWithPermissions(['penjualan.view', 'penjualan.void']);
        $sale = $this->makeCompletedSale();

        $response = $this->post(route('penjualan.void', $sale->id), ['reason' => '']);

        $response->assertSessionHasErrors('reason');
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_voiding_twice_shows_an_error_and_does_not_double_reverse(): void
    {
        $this->actingAsUserWithPermissions(['penjualan.view', 'penjualan.void']);
        $sale = $this->makeCompletedSale();

        $this->post(route('penjualan.void', $sale->id), ['reason' => 'Pertama']);
        $response = $this->post(route('penjualan.void', $sale->id), ['reason' => 'Kedua']);

        $response->assertSessionHas('error');
        $this->assertSame(1, SaleVoid::where('sale_id', $sale->id)->count());
    }

    public function test_show_page_exposes_can_void_prop_based_on_permission(): void
    {
        $this->actingAsUserWithPermissions(['penjualan.view', 'penjualan.void']);
        $sale = $this->makeCompletedSale();

        $response = $this->get(route('penjualan.show', $sale->id));

        $response->assertInertia(fn ($page) => $page->where('canVoid', true));
    }
}
