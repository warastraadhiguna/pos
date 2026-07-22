<?php

namespace Tests\Feature\Api;

use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    public function test_returns_categories(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        ProductCategory::create(['name' => 'Minuman']);

        $response = $this->getJson('/api/v1/product-categories');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Minuman');
        $response->assertJsonStructure(['meta' => ['synced_at']]);
    }

    public function test_updated_since_only_returns_categories_changed_after_the_watermark(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $old = ProductCategory::create(['name' => 'Lama']);
        DB::table('product_categories')->where('id', $old->id)->update(['updated_at' => Carbon::parse('2020-01-01')]);

        $watermark = Carbon::now()->subMinute();
        ProductCategory::create(['name' => 'Baru']);

        $response = $this->getJson('/api/v1/product-categories?'.http_build_query(['updated_since' => $watermark->toIso8601String()]));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Baru'));
        $this->assertFalse($names->contains('Lama'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/product-categories')->assertStatus(401);
    }
}
