<?php

namespace Tests\Feature\Kasir;

use App\Models\CompanySetting;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kasir web menerima cash_received/change_amount SEKARANG (sebelumnya
 * tidak ada field "Uang Diterima" sama sekali di halaman ini) -- lihat
 * Kasir/Index.jsx untuk UI-nya (tombol nominal cepat + "Uang Pas").
 */
class SaleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    private function actingAsKasir(): User
    {
        $role = Role::create(['name' => 'Test Kasir '.uniqid()]);
        $role->permissions()->attach(
            Permission::create(['key' => 'kasir.access', 'label' => 'kasir.access', 'group' => 'Test'])->id,
        );
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_checkout_with_exact_cash_received_creates_a_sale_with_zero_change(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        $response = $this->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);

        $response->assertRedirect(route('kasir.index'));
        $sale = Sale::firstOrFail();
        $this->assertSame(0, bccomp($sale->cash_received, '10000', 4));
        $this->assertSame(0, bccomp($sale->change_amount, '0', 4));
    }

    public function test_successful_checkout_flashes_the_new_sale_id_for_the_print_receipt_prompt(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        $response = $this->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);

        $sale = Sale::firstOrFail();
        $response->assertSessionHas('sale_id', $sale->id);
    }

    public function test_checkout_with_cash_received_greater_than_total_records_the_correct_change(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        // Nominal "cepat" khas (mis. Rp50.000 dari tombol pintasan) untuk
        // transaksi Rp10.000 -> kembalian seharusnya Rp40.000.
        $response = $this->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'change_amount' => 40000,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);

        $response->assertRedirect(route('kasir.index'));
        $sale = Sale::firstOrFail();
        $this->assertSame(0, bccomp($sale->cash_received, '50000', 4));
        $this->assertSame(0, bccomp($sale->change_amount, '40000', 4));
    }

    public function test_cash_received_less_than_grand_total_is_rejected_and_no_sale_is_created(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        $response = $this->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);

        // SaleService melempar exception (server merekonsiliasi ulang,
        // tidak pernah percaya begitu saja) -- Kasir\SaleController
        // menangkapnya lewat try/catch generik & redirect dengan pesan
        // error, bukan 422 seperti endpoint API mobile.
        $response->assertRedirect(route('kasir.index'));
        $response->assertSessionHas('error');
        $this->assertSame(0, Sale::count());
    }

    public function test_cash_received_is_now_required_unlike_the_old_behaviour(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        $response = $this->post('/kasir', [
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);

        $response->assertSessionHasErrors(['cash_received', 'change_amount']);
        $this->assertSame(0, Sale::count());
    }

    public function test_index_page_exposes_payment_quick_amounts(): void
    {
        $this->actingAsKasir();
        CompanySetting::current()->update(['payment_quick_amounts' => [15000, 25000]]);

        $response = $this->get('/kasir');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Kasir/Index')
            ->where('paymentQuickAmounts', [15000, 25000]),
        );
    }

    public function test_index_page_exposes_selectable_cash_accounts(): void
    {
        $this->actingAsKasir();

        $response = $this->get('/kasir');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Kasir/Index')
            ->where('cashAccounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('1-1000')
                && collect($accounts)->pluck('code')->contains('1-1100')),
        );
    }

    public function test_checkout_without_cash_account_code_defaults_to_kas(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        $this->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);

        $sale = Sale::firstOrFail();
        $this->assertSame('1-1000', $sale->cash_account_code);
    }

    /**
     * Kasir web sendiri tidak mengirim product_name hari ini (real-time,
     * tidak butuh) -- tapi field ini opsional & diterima kalau ADA, supaya
     * jalur ini juga siap untuk klien mana pun yang sudah membawa snapshot
     * namanya sendiri (lihat SaleService::createSaleLine()).
     */
    public function test_checkout_accepts_an_optional_product_name_override_per_line(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Nama Server Saat Ini', 'sell_price' => 10000]);

        $this->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000, 'product_name' => 'Nama Titipan Klien'],
            ],
        ]);

        $sale = Sale::firstOrFail();
        $this->assertSame('Nama Titipan Klien', $sale->lines->first()->product_name);
    }

    public function test_checkout_with_bank_selected_stores_the_bank_code(): void
    {
        $this->actingAsKasir();
        $product = Product::create(['name' => 'Kopi', 'sell_price' => 10000]);

        $this->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'cash_account_code' => '1-1100',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);

        $sale = Sale::firstOrFail();
        $this->assertSame('1-1100', $sale->cash_account_code);
    }
}
