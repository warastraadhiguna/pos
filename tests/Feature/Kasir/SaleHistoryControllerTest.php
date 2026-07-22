<?php

namespace Tests\Feature\Kasir;

use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\SaleService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleHistoryControllerTest extends TestCase
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

    private function makeSale(string $date, ?int $cashierId, string $productName, float $price): Sale
    {
        $product = Product::create(['name' => $productName, 'sell_price' => $price]);

        return $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by_user_id' => $cashierId,
            'date' => $date,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => $price],
            ],
        ]);
    }

    public function test_index_defaults_to_the_last_7_days(): void
    {
        $this->actingAsAuthorizedUser();

        $this->travelTo(now()->setDate(2026, 7, 22));

        $inRange = $this->makeSale('2026-07-20', null, 'Produk A', 10000);
        $tooOld = $this->makeSale('2026-07-14', null, 'Produk B', 20000); // 8 hari lalu, di luar default

        $response = $this->get(route('penjualan.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Penjualan/Index')
            ->where('filters.date_from', '2026-07-16')
            ->where('filters.date_to', '2026-07-22')
            ->where('sales', fn ($sales) => collect($sales)->pluck('id')->contains($inRange->id)
                && ! collect($sales)->pluck('id')->contains($tooOld->id)),
        );

        $this->travelBack();
    }

    public function test_date_range_filter_is_inclusive_of_both_boundary_dates(): void
    {
        $this->actingAsAuthorizedUser();

        $start = $this->makeSale('2026-07-01', null, 'Produk Awal', 10000);
        $middle = $this->makeSale('2026-07-05', null, 'Produk Tengah', 10000);
        $end = $this->makeSale('2026-07-10', null, 'Produk Akhir', 10000);
        $before = $this->makeSale('2026-06-30', null, 'Produk Sebelum', 10000);
        $after = $this->makeSale('2026-07-11', null, 'Produk Sesudah', 10000);

        $response = $this->get(route('penjualan.index', ['date_from' => '2026-07-01', 'date_to' => '2026-07-10']));

        $response->assertInertia(fn ($page) => $page
            ->where('sales', function ($sales) use ($start, $middle, $end, $before, $after) {
                $ids = collect($sales)->pluck('id');
                return $ids->contains($start->id) && $ids->contains($middle->id) && $ids->contains($end->id)
                    && ! $ids->contains($before->id) && ! $ids->contains($after->id);
            }),
        );
    }

    public function test_search_finds_a_transaction_by_its_id(): void
    {
        $this->actingAsAuthorizedUser();

        $target = $this->makeSale('2026-07-10', null, 'Kopi Susu', 15000);
        $other = $this->makeSale('2026-07-10', null, 'Teh Manis', 8000);

        $response = $this->get(route('penjualan.index', [
            'date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'search' => (string) $target->id,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('sales', fn ($sales) => collect($sales)->pluck('id')->contains($target->id)
                && ! collect($sales)->pluck('id')->contains($other->id)),
        );
    }

    public function test_search_finds_a_transaction_by_product_name(): void
    {
        $this->actingAsAuthorizedUser();

        $withProduct = $this->makeSale('2026-07-10', null, 'Kopi Susu Spesial', 15000);
        $withoutProduct = $this->makeSale('2026-07-10', null, 'Teh Manis', 8000);

        $response = $this->get(route('penjualan.index', [
            'date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'search' => 'Kopi Susu',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('sales', fn ($sales) => collect($sales)->pluck('id')->contains($withProduct->id)
                && ! collect($sales)->pluck('id')->contains($withoutProduct->id)),
        );
    }

    public function test_cashier_filter_only_shows_sales_by_the_selected_cashier(): void
    {
        $this->actingAsAuthorizedUser();

        $cashierA = User::factory()->create();
        $cashierB = User::factory()->create();

        $saleA = $this->makeSale('2026-07-10', $cashierA->id, 'Produk A', 10000);
        $saleB = $this->makeSale('2026-07-10', $cashierB->id, 'Produk B', 10000);

        $response = $this->get(route('penjualan.index', [
            'date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'cashier_id' => $cashierA->id,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('sales', fn ($sales) => collect($sales)->pluck('id')->contains($saleA->id)
                && ! collect($sales)->pluck('id')->contains($saleB->id)),
        );
    }

    public function test_cashiers_list_only_includes_users_who_have_made_a_sale(): void
    {
        $this->actingAsAuthorizedUser();

        $activeCashier = User::factory()->create(['name' => 'Kasir Aktif']);
        User::factory()->create(['name' => 'Tidak Pernah Jualan']);

        $this->makeSale('2026-07-10', $activeCashier->id, 'Produk A', 10000);

        $response = $this->get(route('penjualan.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('cashiers', fn ($cashiers) => collect($cashiers)->pluck('name')->contains('Kasir Aktif')
                && ! collect($cashiers)->pluck('name')->contains('Tidak Pernah Jualan')),
        );
    }

    public function test_summary_reflects_the_filtered_result_set_using_bcmath_precision(): void
    {
        $this->actingAsAuthorizedUser();

        $this->makeSale('2026-07-10', null, 'Produk A', 10000);
        $this->makeSale('2026-07-10', null, 'Produk B', 25000.5);
        $this->makeSale('2026-08-01', null, 'Produk Luar Rentang', 999999); // di luar filter tanggal

        $response = $this->get(route('penjualan.index', ['date_from' => '2026-07-10', 'date_to' => '2026-07-10']));

        $response->assertInertia(fn ($page) => $page
            ->where('summary.count', 2)
            ->where('summary.total', fn ($total) => bccomp($total, '35000.5000', 4) === 0),
        );
    }

    public function test_unauthorized_user_cannot_access_sale_history(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('penjualan.index'))->assertForbidden();
    }

    public function test_receipt_shows_items_totals_cashier_and_store_identity(): void
    {
        $this->actingAsAuthorizedUser();
        CompanySetting::current()->update([
            'store_name' => 'Toko Maju Jaya',
            'store_address' => 'Jl. Merdeka No. 1',
            'store_phone' => '08123456789',
            'receipt_footer' => 'Terima kasih!',
        ]);
        $cashier = User::factory()->create(['name' => 'Budi Kasir']);

        $sale = $this->makeSale('2026-07-10', $cashier->id, 'Kopi Susu', 15000);

        $response = $this->get(route('penjualan.receipt', $sale->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Penjualan/Receipt')
            ->where('sale.id', $sale->id)
            ->where('sale.lines.0.product.name', 'Kopi Susu')
            ->where('sale.grand_total', '15000.0000')
            ->where('sale.created_by_user.name', 'Budi Kasir')
            ->where('store.name', 'Toko Maju Jaya')
            ->where('store.address', 'Jl. Merdeka No. 1')
            ->where('store.phone', '08123456789')
            ->where('store.footer', 'Terima kasih!'),
        );
    }

    public function test_receipt_reflects_the_sales_own_stored_tax_total_not_the_current_ppn_switch(): void
    {
        $this->actingAsAuthorizedUser();
        CompanySetting::current()->update(['ppn_active' => true]);

        $taxRate = TaxRate::where('name', 'PPN 11%')->firstOrFail();
        $product = Product::create(['name' => 'Produk Kena Pajak', 'sell_price' => 11100, 'tax_rate_id' => $taxRate->id]);
        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 11100]],
        ]);
        $this->assertTrue(bccomp($sale->tax_total, '0', 4) > 0);

        // Saklar PPN dimatikan SETELAH transaksi tercatat -- struk cetak
        // ulang harus tetap menampilkan pajak yang benar-benar tersimpan
        // di transaksi itu (snapshot), bukan ikut saklar terkini.
        CompanySetting::current()->update(['ppn_active' => false]);

        $response = $this->get(route('penjualan.receipt', $sale->id));

        $response->assertInertia(fn ($page) => $page
            ->where('sale.tax_total', fn ($taxTotal) => bccomp($taxTotal, '0', 4) > 0),
        );
    }

    public function test_receipt_has_zero_tax_total_for_an_untaxed_sale(): void
    {
        $this->actingAsAuthorizedUser();
        $sale = $this->makeSale('2026-07-10', null, 'Produk Tanpa Pajak', 10000);

        $response = $this->get(route('penjualan.receipt', $sale->id));

        $response->assertInertia(fn ($page) => $page
            ->where('sale.tax_total', fn ($taxTotal) => bccomp($taxTotal, '0', 4) === 0),
        );
    }

    /**
     * Struk membaca product_name (snapshot dibekukan saat transaksi), bukan
     * relasi product.name (data terkini) — rename produk SETELAH transaksi
     * tidak boleh mengubah nama yang tercetak ulang di struk lama.
     */
    public function test_receipt_uses_the_product_name_snapshot_not_the_live_product_relation(): void
    {
        $this->actingAsAuthorizedUser();

        $product = Product::create(['name' => 'Kopi Susu Original', 'sell_price' => 15000]);
        $sale = $this->sales->createSale([
            'outlet_id' => $this->outlet->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-10',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 15000]],
        ]);

        $product->update(['name' => 'Kopi Susu Rename']);

        $response = $this->get(route('penjualan.receipt', $sale->id));

        $response->assertInertia(fn ($page) => $page
            ->where('sale.lines.0.product_name', 'Kopi Susu Original')
            ->where('sale.lines.0.product.name', 'Kopi Susu Rename'),
        );
    }

    /**
     * Baris lama (product_name NULL, dari sebelum kolom ini ada) harus
     * tetap tersedia lewat resource supaya frontend bisa fallback ke
     * relasi produk terkini (lihat Receipt.jsx/Show.jsx: `product_name ??
     * product.name`).
     */
    public function test_receipt_exposes_null_product_name_for_legacy_rows_so_the_frontend_can_fall_back(): void
    {
        $this->actingAsAuthorizedUser();

        $sale = $this->makeSale('2026-07-10', null, 'Produk Lama', 10000);
        \Illuminate\Support\Facades\DB::table('sale_lines')
            ->where('sale_id', $sale->id)
            ->update(['product_name' => null]);

        $response = $this->get(route('penjualan.receipt', $sale->id));

        $response->assertInertia(fn ($page) => $page
            ->where('sale.lines.0.product_name', null)
            ->where('sale.lines.0.product.name', 'Produk Lama'),
        );
    }

    public function test_unauthorized_user_cannot_access_the_receipt_page(): void
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permission = Permission::create(['key' => 'kasir.access', 'label' => 'Kasir', 'group' => 'Test']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['role_id' => $role->id]);

        $sale = $this->makeSale('2026-07-10', null, 'Produk', 10000);

        $this->actingAs($user)->get(route('penjualan.receipt', $sale->id))->assertForbidden();
    }
}
