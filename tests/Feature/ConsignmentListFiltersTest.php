<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Daftar Konsinyasi (Penerimaan, Retur, Pelunasan) mengingat filter
 * tanggal/cari terakhir -- setelah simpan (redirect ke index tanpa query)
 * daftar tidak kembali ke tanggal default (hari ini).
 */
class ConsignmentListFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        $role = Role::create(['name' => 'Test Role']);
        $role->permissions()->attach(
            Permission::firstOrCreate(['key' => 'konsinyasi.manage'], ['label' => 'x', 'group' => 'x'])->id
        );
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    }

    public function test_each_list_remembers_last_date_filters(): void
    {
        foreach (['konsinyasi.receipts.index', 'konsinyasi.returns.index', 'konsinyasi.payments.index'] as $routeName) {
            $this->get(route($routeName, ['date_from' => '2026-11-01', 'date_to' => '2026-11-15', 'search' => '']))
                ->assertOk();

            // Seperti sesudah simpan / klik "Kembali": tanpa query sama sekali.
            $this->get(route($routeName))
                ->assertInertia(fn ($page) => $page
                    ->where('filters.date_from', '2026-11-01')
                    ->where('filters.date_to', '2026-11-15'));
        }
    }

    public function test_without_any_previous_filter_defaults_to_today(): void
    {
        $today = now()->toDateString();

        $this->get(route('konsinyasi.receipts.index'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.date_from', $today)
                ->where('filters.date_to', $today));
    }
}
