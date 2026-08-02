<?php

namespace Tests\Feature\Master;

use App\Models\NoteTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    private function actingAsAuthorizedUser(): User
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach(
            Permission::create(['key' => 'master-data.manage', 'label' => 'master-data.manage', 'group' => 'Test'])->id,
        );
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_note_template_can_be_created(): void
    {
        $this->actingAsAuthorizedUser();

        $this->post(route('master.note-templates.store'), [
            'text' => 'Tidak pedas',
            'is_active' => true,
        ])->assertRedirect(route('master.note-templates.index'));

        $noteTemplate = NoteTemplate::where('text', 'Tidak pedas')->firstOrFail();
        $this->assertTrue($noteTemplate->is_active);
    }

    public function test_note_template_can_be_updated(): void
    {
        $this->actingAsAuthorizedUser();
        $noteTemplate = NoteTemplate::create(['text' => 'Teks Lama']);

        $this->put(route('master.note-templates.update', $noteTemplate), [
            'text' => 'Teks Baru',
            'is_active' => true,
        ])->assertRedirect(route('master.note-templates.index'));

        $this->assertSame('Teks Baru', $noteTemplate->fresh()->text);
    }

    public function test_note_template_can_be_deactivated_via_the_edit_form(): void
    {
        $this->actingAsAuthorizedUser();
        $noteTemplate = NoteTemplate::create(['text' => 'Template Aktif', 'is_active' => true]);

        $this->put(route('master.note-templates.update', $noteTemplate), [
            'text' => 'Template Aktif',
            'is_active' => false,
        ])->assertRedirect(route('master.note-templates.index'));

        $this->assertFalse($noteTemplate->fresh()->is_active);
    }

    /**
     * Beda dari Member/DiningTable: note_templates tidak direferensikan FK
     * apa pun (lihat docblock model NoteTemplate), jadi delete di sini
     * SELALU sukses, tidak pernah diblokir constraint database -- bukan
     * hanya kalau belum dipakai di transaksi manapun.
     */
    public function test_note_template_can_always_be_deleted(): void
    {
        $this->actingAsAuthorizedUser();
        $noteTemplate = NoteTemplate::create(['text' => 'Template Untuk Dihapus']);

        $this->delete(route('master.note-templates.destroy', $noteTemplate))
            ->assertRedirect(route('master.note-templates.index'));

        $this->assertSame(0, NoteTemplate::count());
    }

    public function test_unauthorized_user_cannot_manage_note_templates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('master.note-templates.index'))->assertForbidden();
    }
}
