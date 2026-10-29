<?php

namespace Tests\Feature\Kasir;

use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleLineHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $sales;

    private Outlet $outlet;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->sales = app(SaleService::class);
        $this->outlet = Outlet::first();
        $this->warehouse = Warehouse::first();
    }

    private function actingAsAuthorizedUser(): User
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'penjualan.view', 'label' => 'Riwayat Penjualan', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $lines  [produk, qty]
     */
    private function makeSale(string $date, array $lines, ?int $cashierId = null, string $paymentMethod = 'cash'): Sale
    {
        return $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by_user_id' => $cashierId,
            'date' => $date,
            'payment_method' => $paymentMethod,
            'cash_account_code' => $paymentMethod === 'qris' ? '1-1100' : null,
            'lines' => collect($lines)->map(fn (array $line) => [
                'product_id' => $line[0]->id,
                'qty' => $line[1],
                'unit_price' => $line[0]->sell_price,
            ])->all(),
        ]);
    }

    private function product(string $name, float $price, ?ProductCategory $category = null): Product
    {
        return Product::create(['name' => $name, 'sell_price' => $price, 'product_category_id' => $category?->id]);
    }

    public function test_requires_the_penjualan_view_permission(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('penjualan-per-produk.index'))->assertForbidden();
    }

    public function test_lists_one_row_per_sale_line_and_recaps_per_product(): void
    {
        $this->actingAsAuthorizedUser();
        $teh = $this->product('Teh', 3000);
        $kopi = $this->product('Kopi', 5000);

        $first = $this->makeSale('2026-07-10', [[$teh, 2], [$kopi, 1]]);
        $this->makeSale('2026-07-11', [[$teh, 1]]);

        $response = $this->get(route('penjualan-per-produk.index', ['date_from' => '2026-07-10', 'date_to' => '2026-07-11']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Penjualan/PerProduk')
            ->has('rows', 3)
            ->where('rows', fn ($rows) => collect($rows)->where('sale_id', $first->id)->count() === 2
                && collect($rows)->every(fn ($row) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['date']) === 1))
            ->where('recap', function ($recap) {
                $teh = collect($recap)->firstWhere('product_name', 'Teh');

                return count($recap) === 2
                    && $teh['qty'] === '3.0000'
                    && $teh['total'] === '9000.0000'
                    && $teh['transaction_count'] === 2;
            })
            ->where('summary.qty', '4.0000')
            ->where('summary.total', '14000.0000'),
        );
    }

    public function test_filters_by_product_category(): void
    {
        $this->actingAsAuthorizedUser();
        $minuman = ProductCategory::create(['name' => 'Minuman']);
        $makanan = ProductCategory::create(['name' => 'Makanan']);
        $teh = $this->product('Teh', 3000, $minuman);
        $roti = $this->product('Roti', 8000, $makanan);

        $this->makeSale('2026-07-10', [[$teh, 1], [$roti, 1]]);

        $response = $this->get(route('penjualan-per-produk.index', [
            'date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'product_category_id' => $minuman->id,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.product_name', 'Teh')
            ->where('rows.0.category', 'Minuman'),
        );
    }

    public function test_filters_by_cashier_and_payment_method(): void
    {
        $this->actingAsAuthorizedUser();
        $kasirA = User::factory()->create(['name' => 'Kasir A']);
        $kasirB = User::factory()->create(['name' => 'Kasir B']);
        $teh = $this->product('Teh', 3000);

        $expected = $this->makeSale('2026-07-10', [[$teh, 1]], $kasirA->id, 'qris');
        $this->makeSale('2026-07-10', [[$teh, 1]], $kasirA->id, 'cash');
        $this->makeSale('2026-07-10', [[$teh, 1]], $kasirB->id, 'qris');

        $response = $this->get(route('penjualan-per-produk.index', [
            'date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'cashier_id' => $kasirA->id, 'payment_method' => 'qris',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.sale_id', $expected->id)
            ->where('rows.0.cashier', 'Kasir A'),
        );
    }

    public function test_search_matches_transaction_number_or_product_name(): void
    {
        $this->actingAsAuthorizedUser();
        $teh = $this->product('Teh Manis', 3000);
        $kopi = $this->product('Kopi Susu', 5000);

        $tehSale = $this->makeSale('2026-07-10', [[$teh, 1]]);
        $kopiSale = $this->makeSale('2026-07-10', [[$kopi, 1]]);

        $this->get(route('penjualan-per-produk.index', [
            'date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'search' => 'manis',
        ]))->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.sale_id', $tehSale->id));

        $this->get(route('penjualan-per-produk.index', [
            'date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'search' => '#'.$kopiSale->id,
        ]))->assertInertia(fn ($page) => $page
            ->where('rows', fn ($rows) => collect($rows)->pluck('sale_id')->contains($kopiSale->id)));
    }

    public function test_voided_sales_are_listed_but_excluded_from_totals(): void
    {
        $user = $this->actingAsAuthorizedUser();
        $teh = $this->product('Teh', 3000);

        $this->makeSale('2026-07-10', [[$teh, 1]]);
        $voided = $this->makeSale('2026-07-10', [[$teh, 5]]);
        $this->sales->voidSale($voided, 'salah input', $user->id);

        $response = $this->get(route('penjualan-per-produk.index', ['date_from' => '2026-07-10', 'date_to' => '2026-07-10']));

        $response->assertInertia(fn ($page) => $page
            ->has('rows', 2)
            ->where('summary.qty', '1.0000')
            ->where('summary.total', '3000.0000')
            ->where('summary.void_line_count', 1),
        );
    }
}
