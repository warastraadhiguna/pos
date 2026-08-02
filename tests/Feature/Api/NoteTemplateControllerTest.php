<?php

namespace Tests\Feature\Api;

use App\Models\NoteTemplate;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NoteTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    public function test_returns_note_template_master_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        NoteTemplate::create(['text' => 'Tidak pedas']);

        $response = $this->getJson('/api/v1/note-templates');

        $response->assertOk();
        $response->assertJsonPath('data.0.text', 'Tidak pedas');
        $response->assertJsonPath('data.0.is_active', true);
        $response->assertJsonStructure(['meta' => ['synced_at']]);
    }

    /**
     * Deactivated templates must still sync (not silently disappear) so a
     * client that already cached one learns it was deactivated -- same
     * convention as Member/Table's is_active.
     */
    public function test_deactivated_note_templates_are_still_included(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        NoteTemplate::create(['text' => 'Template Nonaktif', 'is_active' => false]);

        $response = $this->getJson('/api/v1/note-templates');

        $response->assertOk();
        $response->assertJsonPath('data.0.is_active', false);
    }

    public function test_updated_since_only_returns_note_templates_changed_after_the_watermark(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $old = NoteTemplate::create(['text' => 'Template Lama']);
        DB::table('note_templates')->where('id', $old->id)->update(['updated_at' => Carbon::parse('2020-01-01')]);

        $watermark = Carbon::now()->subMinute();
        NoteTemplate::create(['text' => 'Template Baru']);

        $response = $this->getJson('/api/v1/note-templates?'.http_build_query(['updated_since' => $watermark->toIso8601String()]));

        $response->assertOk();
        $texts = collect($response->json('data'))->pluck('text');
        $this->assertTrue($texts->contains('Template Baru'));
        $this->assertFalse($texts->contains('Template Lama'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/note-templates')->assertStatus(401);
    }
}
