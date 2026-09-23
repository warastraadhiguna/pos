<?php

namespace Tests\Feature\Api;

use App\Models\Outlet;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /api/v1/sales/status-changes` -- pull SATU ARAH (server -> mobile)
 * status sale yang berubah (lihat rancangan fitur Void). Pola test mirror
 * `Api/SaleControllerTest.php`: token sungguhan lewat AuthController, bukan
 * Sanctum::actingAs().
 */
class SaleStatusChangesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        $token = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_name' => 'Kasir HP Budi',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function makeSale(string $status): Sale
    {
        return Sale::create([
            'outlet_id' => Outlet::first()->id,
            'warehouse_id' => Warehouse::first()->id,
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'occurred_at' => '2026-07-04 10:00:00',
            'payment_method' => 'cash',
            'cash_account_code' => '1-1000',
            'status' => $status,
            'subtotal' => 10000,
            'tax_total' => 0,
            'grand_total' => 10000,
            'cash_received' => 10000,
            'change_amount' => 0,
        ]);
    }

    public function test_only_non_completed_sales_are_returned(): void
    {
        $completed = $this->makeSale('completed');
        $voided = $this->makeSale('void');

        $response = $this->getJson('/api/v1/sales/status-changes');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('local_uuid');
        $this->assertTrue($uuids->contains($voided->local_uuid));
        $this->assertFalse($uuids->contains($completed->local_uuid));
    }

    public function test_response_shape_has_local_uuid_status_and_synced_at_watermark(): void
    {
        $voided = $this->makeSale('void');

        $response = $this->getJson('/api/v1/sales/status-changes');

        $response->assertOk()
            ->assertJsonPath('data.0.local_uuid', $voided->local_uuid)
            ->assertJsonPath('data.0.status', 'void')
            ->assertJsonStructure(['meta' => ['synced_at']]);
    }

    public function test_updated_since_excludes_sales_voided_before_the_watermark(): void
    {
        $old = $this->makeSale('void');
        // Query builder mentah, BUKAN $old->update() -- Eloquent's save()
        // otomatis menimpa updated_at ke waktu SEKARANG, mengabaikan nilai
        // yang dikirim; query builder langsung tidak melakukan itu.
        Sale::whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);

        $response = $this->getJson('/api/v1/sales/status-changes?'.http_build_query(['updated_since' => now()->toIso8601String()]));

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('local_uuid');
        $this->assertFalse($uuids->contains($old->local_uuid));
    }
}
