<?php

namespace Tests\Feature\Api;

use App\Models\Member;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    public function test_returns_member_master_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Member::create(['name' => 'Budi Santoso', 'phone' => '0812-1111-2222', 'email' => 'budi@example.com']);

        $response = $this->getJson('/api/v1/members');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Budi Santoso');
        $response->assertJsonPath('data.0.phone', '0812-1111-2222');
        $response->assertJsonPath('data.0.email', 'budi@example.com');
        $response->assertJsonPath('data.0.is_active', true);
        $response->assertJsonStructure(['meta' => ['synced_at']]);
    }

    /**
     * Deactivated members must still sync (not silently disappear) so a
     * client that already cached one learns it was deactivated — same
     * convention as Product/Item's is_active. Filtering for the checkout
     * picker (active only) is the client's job, not this endpoint's.
     */
    public function test_deactivated_members_are_still_included(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Member::create(['name' => 'Member Nonaktif', 'is_active' => false]);

        $response = $this->getJson('/api/v1/members');

        $response->assertOk();
        $response->assertJsonPath('data.0.is_active', false);
    }

    public function test_updated_since_only_returns_members_changed_after_the_watermark(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $old = Member::create(['name' => 'Member Lama']);
        DB::table('members')->where('id', $old->id)->update(['updated_at' => Carbon::parse('2020-01-01')]);

        $watermark = Carbon::now()->subMinute();
        Member::create(['name' => 'Member Baru']);

        $response = $this->getJson('/api/v1/members?'.http_build_query(['updated_since' => $watermark->toIso8601String()]));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Member Baru'));
        $this->assertFalse($names->contains('Member Lama'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/members')->assertStatus(401);
    }
}
