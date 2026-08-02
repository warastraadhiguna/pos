<?php

namespace Tests\Feature\Api;

use App\Models\DiningTable;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    public function test_returns_table_master_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        DiningTable::create(['name' => 'Meja 1', 'capacity' => 4, 'area' => 'indoor']);

        $response = $this->getJson('/api/v1/tables');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Meja 1');
        $response->assertJsonPath('data.0.capacity', 4);
        $response->assertJsonPath('data.0.area', 'indoor');
        $response->assertJsonPath('data.0.is_active', true);
        $response->assertJsonStructure(['meta' => ['synced_at']]);
    }

    /**
     * Deactivated tables must still sync (not silently disappear) so a
     * client that already cached one learns it was deactivated — same
     * convention as Member/Product/Item's is_active. Filtering for the
     * checkout picker (active only) is the client's job, not this
     * endpoint's.
     */
    public function test_deactivated_tables_are_still_included(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        DiningTable::create(['name' => 'Meja Nonaktif', 'is_active' => false]);

        $response = $this->getJson('/api/v1/tables');

        $response->assertOk();
        $response->assertJsonPath('data.0.is_active', false);
    }

    public function test_updated_since_only_returns_tables_changed_after_the_watermark(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $old = DiningTable::create(['name' => 'Meja Lama']);
        DB::table('tables')->where('id', $old->id)->update(['updated_at' => Carbon::parse('2020-01-01')]);

        $watermark = Carbon::now()->subMinute();
        DiningTable::create(['name' => 'Meja Baru']);

        $response = $this->getJson('/api/v1/tables?'.http_build_query(['updated_since' => $watermark->toIso8601String()]));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Meja Baru'));
        $this->assertFalse($names->contains('Meja Lama'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/tables')->assertStatus(401);
    }
}
