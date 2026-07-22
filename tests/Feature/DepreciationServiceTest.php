<?php

namespace Tests\Feature;

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\DepreciationService;
use App\Services\FixedAssetService;
use App\Services\PostingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class DepreciationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FixedAssetService $fixedAssets;

    private DepreciationService $depreciation;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $posting = new PostingService();
        $this->fixedAssets = new FixedAssetService($posting, new CashAccountService());
        $this->depreciation = new DepreciationService($posting, $this->fixedAssets);
        $this->outlet = Outlet::first();
    }

    private function makeAsset(array $overrides = []): FixedAsset
    {
        return $this->fixedAssets->recordPurchase(array_merge([
            'outlet_id' => $this->outlet->id,
            'name' => 'Kulkas Sanken',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 12000000,
            'residual_value' => 0,
            'useful_life_months' => 48, // 250.000/bulan
            'payment_method' => 'cash',
        ], $overrides));
    }

    public function test_process_for_period_posts_straight_line_amount_and_a_balanced_journal(): void
    {
        $asset = $this->makeAsset();

        $entries = $this->depreciation->processForPeriod('2026-02', '2026-02-28');

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame(0, bccomp($entry->amount, '250000', 4));
        $this->assertSame('2026-02', $entry->period);

        $journal = Journal::where('source_type', DepreciationEntry::class)->where('source_id', $entry->id)->firstOrFail();
        $lines = $journal->lines()->with('account')->get()->keyBy(fn (JournalLine $line) => $line->account->code);

        $this->assertSame(0, bccomp($lines['5-4000']->debit, '250000', 4));
        $this->assertSame(0, bccomp($lines['1-2900']->credit, '250000', 4));

        $this->assertSame(0, bccomp($this->fixedAssets->accumulatedDepreciation($asset), '250000', 4));
        $this->assertSame(0, bccomp($this->fixedAssets->bookValue($asset), '11750000', 4));
    }

    public function test_processing_the_same_period_twice_does_not_create_a_duplicate_entry(): void
    {
        $this->makeAsset();

        $first = $this->depreciation->processForPeriod('2026-02', '2026-02-28');
        $second = $this->depreciation->processForPeriod('2026-02', '2026-02-28');

        $this->assertCount(1, $first);
        $this->assertCount(0, $second); // sudah diproses -- dilewati, bukan error
        $this->assertSame(1, DepreciationEntry::count());
    }

    public function test_preview_excludes_assets_already_processed_for_the_period(): void
    {
        $this->makeAsset();
        $this->depreciation->processForPeriod('2026-02', '2026-02-28');

        $preview = $this->depreciation->previewForPeriod('2026-02');
        $this->assertCount(0, $preview);

        $previewNextMonth = $this->depreciation->previewForPeriod('2026-03');
        $this->assertCount(1, $previewNextMonth);
    }

    public function test_depreciation_never_goes_negative_and_stops_exactly_at_zero_book_value(): void
    {
        // 3 bulan masa manfaat, tanpa residu, harga 300.000 -> 100.000/bulan
        // PAS (habis dibagi) -> 3 entri sama besar, berhenti tepat di nol.
        $asset = $this->fixedAssets->recordPurchase([
            'outlet_id' => $this->outlet->id,
            'name' => 'Aset Pendek',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 300000,
            'residual_value' => 0,
            'useful_life_months' => 3,
            'payment_method' => 'cash',
        ]);

        $this->depreciation->processForPeriod('2026-02', '2026-02-28');
        $this->depreciation->processForPeriod('2026-03', '2026-03-31');
        $entries3 = $this->depreciation->processForPeriod('2026-04', '2026-04-30');

        $this->assertCount(1, $entries3);
        $this->assertSame(0, bccomp($entries3[0]->amount, '100000', 4));
        $this->assertSame(0, bccomp($this->fixedAssets->bookValue($asset), '0', 4));

        // Sudah habis -- periode berikutnya tidak menghasilkan entri apa pun
        // (bukan entri Rp0, dan TIDAK PERNAH jadi negatif).
        $entries4 = $this->depreciation->processForPeriod('2026-05', '2026-05-31');
        $this->assertCount(0, $entries4);
        $this->assertSame(3, DepreciationEntry::where('fixed_asset_id', $asset->id)->count());
        $this->assertSame(0, bccomp($this->fixedAssets->bookValue($asset), '0', 4));
    }

    /**
     * Kalau (harga - residu) tidak habis dibagi rata oleh masa manfaat
     * (mis. 100.000 / 3 bulan = 33.333,3333 dengan sisa desimal), MIN()
     * di previewForPeriod() memastikan periode terakhir hanya
     * men-debit SISA yang benar-benar tinggal (betapa pun kecil),
     * bukan jumlah bulanan penuh yang akan membuatnya negatif.
     */
    public function test_depreciation_caps_the_final_period_to_the_exact_remaining_amount_when_life_does_not_divide_evenly(): void
    {
        $asset = $this->fixedAssets->recordPurchase([
            'outlet_id' => $this->outlet->id,
            'name' => 'Aset Tidak Pas',
            'purchase_date' => '2026-01-01',
            'acquisition_cost' => 100000,
            'residual_value' => 0,
            'useful_life_months' => 3, // 33.333,3333/bulan
            'payment_method' => 'cash',
        ]);

        $periods = ['2026-02' => '2026-02-28', '2026-03' => '2026-03-31', '2026-04' => '2026-04-30', '2026-05' => '2026-05-31'];
        $totalEntries = 0;

        foreach ($periods as $period => $date) {
            $totalEntries += count($this->depreciation->processForPeriod($period, $date));

            // Nilai buku TIDAK PERNAH negatif di titik mana pun.
            $this->assertTrue(bccomp($this->fixedAssets->bookValue($asset), '0', 4) >= 0);
        }

        // Sisa desimal 0,0001 butuh satu periode tambahan kecil untuk
        // benar-benar mencapai nol -- total 4 entri, bukan 3.
        $this->assertSame(4, $totalEntries);
        $this->assertSame(0, bccomp($this->fixedAssets->bookValue($asset), '0', 4));

        // Setelah benar-benar nol, periode berikutnya tidak memproses apa pun.
        $noMore = $this->depreciation->processForPeriod('2026-06', '2026-06-30');
        $this->assertCount(0, $noMore);
    }

    public function test_depreciation_stops_at_residual_value_not_zero_when_residual_is_set(): void
    {
        // Residu 2.000.000, masa manfaat 4 bulan -> (12jt-2jt)/4 = 2.500.000/bulan.
        $asset = $this->makeAsset(['acquisition_cost' => 12000000, 'residual_value' => 2000000, 'useful_life_months' => 4]);

        $this->depreciation->processForPeriod('2026-02', '2026-02-28');
        $this->depreciation->processForPeriod('2026-03', '2026-03-31');
        $this->depreciation->processForPeriod('2026-04', '2026-04-30');
        $this->depreciation->processForPeriod('2026-05', '2026-05-31');

        // Nilai buku berhenti PERSIS di nilai residu, bukan nol.
        $this->assertSame(0, bccomp($this->fixedAssets->bookValue($asset), '2000000', 4));

        $noMore = $this->depreciation->processForPeriod('2026-06', '2026-06-30');
        $this->assertCount(0, $noMore);
    }

    public function test_processing_multiple_assets_for_the_same_period_creates_one_entry_each(): void
    {
        $assetA = $this->makeAsset(['name' => 'Aset A']);
        $assetB = $this->makeAsset(['name' => 'Aset B', 'acquisition_cost' => 6000000, 'useful_life_months' => 24]);

        $entries = $this->depreciation->processForPeriod('2026-02', '2026-02-28');

        $this->assertCount(2, $entries);
        $this->assertSame(2, Journal::where('source_type', DepreciationEntry::class)->count());
    }

    public function test_rejects_an_invalid_period_format(): void
    {
        $this->makeAsset();

        $this->expectException(InvalidArgumentException::class);

        $this->depreciation->processForPeriod('2026/02', '2026-02-28');
    }

    // Prinsip #5: kalau satu aset di batch gagal, semua batal (dites lewat
    // transaksi manual yang sengaja dijatuhkan di tengah -- di sini kita
    // pastikan minimal bahwa processForPeriod() sungguhan terbungkus dalam
    // satu transaction dengan memverifikasi tidak ada baris yang tertinggal
    // kalau period tidak valid dilempar sebelum loop dimulai.
    public function test_invalid_period_does_not_create_any_rows(): void
    {
        $this->makeAsset();

        try {
            $this->depreciation->processForPeriod('invalid', '2026-02-28');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(0, DepreciationEntry::count());
        $this->assertSame(0, Journal::where('source_type', DepreciationEntry::class)->count());
    }
}
