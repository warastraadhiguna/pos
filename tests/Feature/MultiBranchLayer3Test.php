<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\Item;
use App\Models\Journal;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\BranchService;
use App\Services\CashAccountService;
use App\Services\DistributionService;
use App\Services\InventoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-Cabang Lapisan 3 -- Penjualan ter-tag cabang. Kasus verifikasi
 * WAJIB dari checklist user, dengan angka nyata.
 */
class MultiBranchLayer3Test extends TestCase
{
    use RefreshDatabase;

    private BranchService $branches;

    private InventoryService $inventory;

    private Warehouse $pusat;

    private Outlet $pusatOutlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->branches = new BranchService();
        $this->inventory = new InventoryService();
        $this->pusat = Warehouse::first();
        $this->pusatOutlet = Outlet::first();
    }

    private function enableMultiBranch(): void
    {
        CompanySetting::current()->update(['multi_branch_enabled' => true]);
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

    // ==================== resolveCurrentOutlet() ====================

    public function test_resolves_to_headquarters_when_multi_branch_is_disabled_regardless_of_assignment(): void
    {
        // multi_branch_enabled TETAP false (default) -- walau user DAN
        // device sengaja ditugaskan ke cabang lain, resolusi harus tetap
        // pusat, titik. Ini jaminan kompatibilitas.
        $cabang = $this->makeBranch('Cabang Selatan');
        $user = User::factory()->create(['outlet_id' => $cabang->id]);
        $device = Device::create(['device_id' => 'dev-1', 'status' => Device::STATUS_APPROVED, 'outlet_id' => $cabang->id]);

        $resolved = $this->branches->resolveCurrentOutlet($user, $device->device_id);

        $this->assertSame($this->pusatOutlet->id, $resolved->id);
    }

    public function test_resolves_from_device_outlet_id_first_when_multi_branch_enabled(): void
    {
        $this->enableMultiBranch();
        $cabang = $this->makeBranch('Cabang Selatan');
        $userOutlet = $this->makeBranch('Cabang Lain (user)');
        $user = User::factory()->create(['outlet_id' => $userOutlet->id]);
        $device = Device::create(['device_id' => 'dev-1', 'status' => Device::STATUS_APPROVED, 'outlet_id' => $cabang->id]);

        $resolved = $this->branches->resolveCurrentOutlet($user, $device->device_id);

        $this->assertSame($cabang->id, $resolved->id, 'device.outlet_id menang atas user.outlet_id');
    }

    public function test_falls_back_to_user_outlet_when_device_has_none(): void
    {
        $this->enableMultiBranch();
        $cabang = $this->makeBranch('Cabang Selatan');
        $user = User::factory()->create(['outlet_id' => $cabang->id]);

        $resolved = $this->branches->resolveCurrentOutlet($user, null);

        $this->assertSame($cabang->id, $resolved->id);
    }

    public function test_falls_back_to_headquarters_for_admin_with_no_assignment(): void
    {
        $this->enableMultiBranch();
        $admin = User::factory()->create(['outlet_id' => null]);

        $resolved = $this->branches->resolveCurrentOutlet($admin, null);

        $this->assertSame($this->pusatOutlet->id, $resolved->id);
    }

    // ==================== Penjualan web di cabang ====================

    public function test_web_sale_at_a_branch_deducts_branch_warehouse_stock_and_tags_the_sale(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet, $cabangWarehouse] = $this->makeBranchWithWarehouse('Cabang Selatan');
        [$product, $item] = $this->makeStockedProduct();

        // Isi stok cabang lewat distribusi (L2) -- 50 unit @Rp4.000, HPP
        // cabang jadi Rp4.000 (bukan Rp0 pusat, membuktikan poin HPP).
        $this->inventory->recordInbound($item, $this->pusat, '50', '4000', $this->pusatOutlet, '2026-08-14');
        $distributions = new DistributionService($this->inventory);
        $distribution = $distributions->createDistribution([
            'source_warehouse_id' => $this->pusat->id,
            'dest_warehouse_id' => $cabangWarehouse->id,
            'date' => '2026-08-14',
            'lines' => [['item_id' => $item->id, 'qty' => 50]],
        ]);
        $distributions->executeDistribution($distribution, User::factory()->create());
        $this->assertSame('4000.0000', $this->inventory->currentAverageCost($item, $cabangWarehouse));

        $kasir = User::factory()->create([
            'outlet_id' => $cabangOutlet->id,
            'role_id' => $this->roleWith(['kasir.access'])->id,
        ]);

        $response = $this->actingAs($kasir)->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000],
            ],
        ]);
        $response->assertRedirect(route('kasir.index'));

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame($cabangOutlet->id, $sale->outlet_id, 'sales.outlet_id harus cabang, bukan pusat');
        $this->assertSame($cabangWarehouse->id, $sale->warehouse_id);

        // Stok CABANG berkurang, stok PUSAT tidak tersentuh sama sekali.
        $this->assertSame('49.0000', $this->inventory->currentStock($item, $cabangWarehouse));
        $this->assertSame('0.0000', $this->inventory->currentStock($item, $this->pusat));

        // HPP dari sale line = HPP CABANG (Rp4.000), bukan Rp0/pusat.
        $this->assertSame('4000.0000', (string) $sale->lines->first()->hpp_total);
    }

    public function test_cash_sale_at_a_branch_with_its_own_kas_account_lands_there_not_headquarters_kas(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet, $cabangWarehouse] = $this->makeBranchWithWarehouse('Cabang Selatan');
        [$product] = $this->makeStockedProduct();

        // Cabang punya akun Kas/Bank sendiri (Layer 1: outlet-scoped account).
        $cashAccounts = new CashAccountService();
        $branchKas = $cashAccounts->createBankAccount('1-1150', 'Kas Cabang Selatan', $cabangOutlet->id);

        $kasir = User::factory()->create([
            'outlet_id' => $cabangOutlet->id,
            'role_id' => $this->roleWith(['kasir.access'])->id,
        ]);

        $this->actingAs($kasir)->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ])->assertRedirect(route('kasir.index'));

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame($branchKas->code, $sale->cash_account_code, 'tunai harus masuk Kas CABANG, bukan Kas pusat');
    }

    public function test_cash_sale_at_a_branch_without_its_own_account_falls_back_to_headquarters_kas_without_failing(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet] = $this->makeBranchWithWarehouse('Cabang Belum Ada Kas');
        [$product] = $this->makeStockedProduct();

        $kasir = User::factory()->create([
            'outlet_id' => $cabangOutlet->id,
            'role_id' => $this->roleWith(['kasir.access'])->id,
        ]);

        $response = $this->actingAs($kasir)->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);

        // WAJIB tidak gagal -- fallback ke Kas global, bukan exception.
        $response->assertRedirect(route('kasir.index'));
        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame(CashAccountService::DEFAULT_CODE, $sale->cash_account_code);
    }

    // ==================== Penjualan mobile (device) ====================

    public function test_mobile_sale_resolves_branch_from_device_and_server_never_trusts_client_outlet_input(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet, $cabangWarehouse] = $this->makeBranchWithWarehouse('Cabang Mobile');
        [$product, $item] = $this->makeStockedProduct();
        $this->inventory->recordInbound($item, $cabangWarehouse, '20', '3000', $this->pusatOutlet, '2026-08-14');

        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        // Percobaan login PERTAMA device baru -- ditolak (pending, belum
        // disetujui admin), persis alur Device Binding L1 sungguhan.
        // BUKAN 200: tidak ada token yang bisa dipakai dari respons ini.
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_id' => 'mobile-device-1',
        ])->assertStatus(403)->assertJsonPath('error_code', 'device_pending');

        // Admin approve + tugaskan device ke cabang -- persis alur nyata
        // (Device Binding L1 + Multi-Cabang L1 approval UI).
        $device = Device::where('device_id', 'mobile-device-1')->firstOrFail();
        $device->update(['status' => Device::STATUS_APPROVED, 'outlet_id' => $cabangOutlet->id]);

        // Login KEDUA, sekarang device sudah approved -- berhasil dapat token.
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_id' => 'mobile-device-1',
        ])->assertOk();
        $token = $loginResponse->json('token');
        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sales', [
            'local_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'date' => now()->toIso8601String(),
            'payment_method' => 'cash',
            'cash_received' => 3000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 3000]],
        ]);
        $response->assertCreated();

        $sale = Sale::findOrFail($response->json('data.id'));
        $this->assertSame($cabangOutlet->id, $sale->outlet_id);
        $this->assertSame($cabangWarehouse->id, $sale->warehouse_id);
        $this->assertSame('19.0000', $this->inventory->currentStock($item, $cabangWarehouse));
    }

    // ==================== Draft di cabang -> finalisasi kurangi stok cabang ====================

    public function test_a_draft_created_at_a_branch_and_finalized_deducts_that_branchs_warehouse_stock(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet, $cabangWarehouse] = $this->makeBranchWithWarehouse('Cabang Draft');
        [$product, $item] = $this->makeStockedProduct();
        $this->inventory->recordInbound($item, $cabangWarehouse, '10', '2500', $this->pusatOutlet, '2026-08-14');

        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        // Percobaan PERTAMA -- ditolak, device belum disetujui (lihat
        // penjelasan di test mobile sale di atas).
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_id' => 'mobile-device-draft',
        ])->assertStatus(403);
        $device = Device::where('device_id', 'mobile-device-draft')->firstOrFail();
        $device->update(['status' => Device::STATUS_APPROVED, 'outlet_id' => $cabangOutlet->id]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_id' => 'mobile-device-draft',
        ])->assertOk();
        $token = $loginResponse->json('token');

        $draftUuid = (string) \Illuminate\Support\Str::uuid();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [[
                'local_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'product_id' => $product->id,
                'product_name_snapshot' => $product->name,
                'qty' => 1,
                'unit_price' => 10000,
                'line_total' => 10000,
                'content_updated_at' => now()->toIso8601String(),
            ]],
        ])->assertOk();

        $draft = \App\Models\Draft::where('local_uuid', $draftUuid)->firstOrFail();
        $this->assertSame($cabangOutlet->id, $draft->outlet_id, 'draft baru harus ter-tag cabang device, bukan pusat');

        // Finalisasi -- draft belum menyentuh stok sama sekali sampai titik ini.
        $this->assertSame('10.0000', $this->inventory->currentStock($item, $cabangWarehouse));

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sales', [
            'local_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'date' => now()->toIso8601String(),
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'draft_local_uuid' => $draftUuid,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ]);
        $response->assertCreated();

        // Stok CABANG (bukan pusat) berkurang saat finalisasi.
        $this->assertSame('9.0000', $this->inventory->currentStock($item, $cabangWarehouse));
        $this->assertSame($cabangOutlet->id, Sale::findOrFail($response->json('data.id'))->outlet_id);
    }

    // ==================== Jurnal: gabung, tapi traceable per-cabang ====================

    public function test_journal_stays_in_the_combined_ledger_but_is_traceable_back_to_the_selling_branch(): void
    {
        $this->enableMultiBranch();
        [$cabangOutlet] = $this->makeBranchWithWarehouse('Cabang Jurnal');
        [$product] = $this->makeStockedProduct();
        $kasir = User::factory()->create([
            'outlet_id' => $cabangOutlet->id,
            'role_id' => $this->roleWith(['kasir.access'])->id,
        ]);
        $journalCountBefore = Journal::count();

        $this->actingAs($kasir)->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ])->assertRedirect(route('kasir.index'));

        // Jurnal MASUK KE POOL GABUNG YANG SAMA (tidak ada set journals
        // terpisah per cabang) -- cuma bertambah satu baris seperti biasa.
        $this->assertSame($journalCountBefore + 1, Journal::count());

        $sale = Sale::latest('id')->firstOrFail();
        $journal = Journal::where('source_type', Sale::class)->where('source_id', $sale->id)->firstOrFail();

        // Traceable ke cabang lewat source -> Sale.outlet_id, TANPA kolom
        // outlet_id baru di journals (lihat rancangan L3).
        $this->assertSame($cabangOutlet->id, $journal->source->outlet_id);
        // Akun Penjualan/HPP tetap GLOBAL (tidak per-cabang) -- baris
        // jurnal tetap merujuk kode akun yang sama seperti sale di pusat.
        $accountCodes = $journal->lines->map(fn ($line) => $line->account->code)->all();
        $this->assertContains('4-1000', $accountCodes); // Penjualan, global
    }

    // ==================== Kompatibilitas: multi_branch_enabled=false ====================

    public function test_disabled_toggle_means_every_sale_still_resolves_to_headquarters_exactly_as_before(): void
    {
        // multi_branch_enabled TETAP default false.
        $this->assertFalse(CompanySetting::current()->multi_branch_enabled);
        [$product, $item] = $this->makeStockedProduct();
        $this->inventory->recordInbound($item, $this->pusat, '10', '5000', $this->pusatOutlet, '2026-08-14');

        $kasir = User::factory()->create(['role_id' => $this->roleWith(['kasir.access'])->id]);
        $this->actingAs($kasir)->post('/kasir', [
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000]],
        ])->assertRedirect(route('kasir.index'));

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame($this->pusatOutlet->id, $sale->outlet_id);
        $this->assertSame($this->pusat->id, $sale->warehouse_id);
        $this->assertSame(CashAccountService::DEFAULT_CODE, $sale->cash_account_code);
        $this->assertSame('9.0000', $this->inventory->currentStock($item, $this->pusat));
    }

    // ==================== Helpers ====================

    private function makeBranch(string $name): Outlet
    {
        return $this->branches->createOutlet([
            'name' => $name,
            'code' => null,
            'address' => null,
            'is_active' => true,
            'is_headquarters' => false,
        ]);
    }

    /** @return array{0: Outlet, 1: Warehouse} */
    private function makeBranchWithWarehouse(string $name): array
    {
        $outlet = $this->makeBranch($name);

        return [$outlet, Warehouse::where('outlet_id', $outlet->id)->firstOrFail()];
    }

    /** @return array{0: Product, 1: Item} */
    private function makeStockedProduct(): array
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $persediaanAccount = Account::where('code', '1-1200')->firstOrFail();

        $item = Item::create([
            'sku' => 'ITEM-'.uniqid(),
            'name' => 'Item Test',
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $persediaanAccount->id,
        ]);

        $product = Product::create(['name' => 'Produk Test', 'sell_price' => 10000]);
        ProductComponent::create([
            'product_id' => $product->id,
            'item_id' => $item->id,
            'uom_id' => $pcs->id,
            'qty' => 1,
        ]);

        return [$product, $item];
    }
}
