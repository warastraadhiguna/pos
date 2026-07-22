<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Uom;
use App\Support\SyncWatermark;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncWatermarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    public function test_now_reads_the_database_clock(): void
    {
        $before = Carbon::now()->subSeconds(5);
        $now = SyncWatermark::now();
        $after = Carbon::now()->addSeconds(5);

        $this->assertTrue($now->greaterThanOrEqualTo($before));
        $this->assertTrue($now->lessThanOrEqualTo($after));
    }

    public function test_without_updated_since_returns_everything_unfiltered(): void
    {
        $this->makeItem('OLD', Carbon::parse('2020-01-01'));

        $results = SyncWatermark::applyIncrementalFilter(Item::query(), null)->get();

        $this->assertCount(1, $results);
    }

    public function test_rows_updated_well_before_the_watermark_are_excluded(): void
    {
        $watermark = Carbon::parse('2026-07-08 10:00:00');
        // 30 detik sebelum watermark — jauh di luar buffer 10 detik.
        $this->makeItem('TOO-OLD', $watermark->copy()->subSeconds(30));

        $results = SyncWatermark::applyIncrementalFilter(Item::query(), $watermark->toIso8601String())->get();

        $this->assertCount(0, $results);
    }

    public function test_rows_updated_within_the_buffer_window_before_the_watermark_are_still_caught(): void
    {
        $watermark = Carbon::parse('2026-07-08 10:00:00');
        // 5 detik sebelum watermark — di dalam jendela buffer 10 detik. Ini
        // justru kasus yang HARUS tetap tertangkap: mensimulasikan transaksi
        // yang menstempel updated_at sebelum watermark tapi baru commit
        // setelahnya (lihat penjelasan di SyncWatermark).
        $this->makeItem('LATE-COMMIT', $watermark->copy()->subSeconds(5));

        $results = SyncWatermark::applyIncrementalFilter(Item::query(), $watermark->toIso8601String())->get();

        $this->assertCount(1, $results);
    }

    public function test_rows_updated_after_the_watermark_are_included(): void
    {
        $watermark = Carbon::parse('2026-07-08 10:00:00');
        $this->makeItem('FRESH', $watermark->copy()->addMinute());

        $results = SyncWatermark::applyIncrementalFilter(Item::query(), $watermark->toIso8601String())->get();

        $this->assertCount(1, $results);
    }

    private function makeItem(string $sku, Carbon $updatedAt): Item
    {
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $account = Account::where('code', '1-1200')->firstOrFail();

        $item = Item::create([
            'sku' => $sku,
            'name' => $sku,
            'costing_type' => 'stocked',
            'base_uom_id' => $pcs->id,
            'purchase_uom_id' => $pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $account->id,
        ]);

        // Timpa updated_at langsung — Eloquent selalu menstempel now() saat
        // create(), jadi ini satu-satunya cara mensimulasikan baris "lama".
        DB::table('items')->where('id', $item->id)->update(['updated_at' => $updatedAt]);

        return $item;
    }
}
