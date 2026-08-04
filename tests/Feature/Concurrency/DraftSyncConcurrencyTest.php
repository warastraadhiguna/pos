<?php

namespace Tests\Feature\Concurrency;

use App\Exceptions\DraftLockedException;
use App\Models\Draft;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DraftSyncService;
use Illuminate\Support\Str;

/**
 * Proves DraftSyncService::hold() is safe under REAL concurrent access from
 * two separate MySQL connections/processes — the soft-lock "satu pemegang
 * dalam satu waktu" guarantee the whole draft-sync design leans on (see
 * docblock DraftSyncService::hold()).
 *
 * Unlike InventoryServiceConcurrencyTest (which proves a caller BLOCKS on a
 * row lock held open for a controlled duration), hold() has nothing to
 * "hold" — it's one atomic `UPDATE ... WHERE` statement. The property this
 * test proves is different and arguably stronger: given two independent
 * connections racing the SAME conditional UPDATE on the SAME unheld row,
 * AT MOST ONE ever succeeds — no read-then-write window either side can
 * slip through. See ConcurrencyTestHoldDraft for the subprocess side.
 */
class DraftSyncConcurrencyTest extends ConcurrencyTestCase
{
    private DraftSyncService $drafts;

    private Outlet $outlet;

    private User $userA;

    private User $userB;

    private Draft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drafts = app(DraftSyncService::class);

        $suffix = uniqid('dsc_');
        $this->outlet = Outlet::create(['name' => 'Concurrency Test Outlet '.$suffix]);
        $this->userA = User::factory()->create(['name' => 'Kasir A '.$suffix]);
        $this->userB = User::factory()->create(['name' => 'Kasir B '.$suffix]);
        $this->draft = Draft::create([
            'outlet_id' => $this->outlet->id,
            'local_uuid' => (string) Str::uuid(),
            'status' => 'open',
        ]);
    }

    protected function tearDown(): void
    {
        $this->draft->delete();
        $this->userA->delete();
        $this->userB->delete();
        $this->outlet->delete();

        parent::tearDown();
    }

    public function test_two_concurrent_hold_attempts_from_separate_processes_on_the_same_unheld_draft_only_one_succeeds(): void
    {
        // Proses B ("penantang") -- subprocess OS sungguhan, koneksi MySQL
        // sendiri, memanggil hold() untuk userB begitu ia mencetak READY.
        $processB = $this->spawnArtisan([
            'concurrency-test:hold-draft',
            (string) $this->draft->id,
            (string) $this->userB->id,
            'HP Kasir B (subprocess)',
        ]);

        $this->waitForMarker($processB, 'READY');

        // Proses A ("penantang lain") -- proses test utama ini sendiri,
        // koneksi Laravel normal, memanggil hold() untuk userA SESEGERA
        // mungkin setelah B menyatakan siap -- keduanya sekarang benar-
        // benar berlomba terhadap baris `drafts` yang sama, dari dua
        // koneksi MySQL independen.
        $succeededA = true;
        try {
            $this->drafts->hold($this->draft, $this->userA, 'HP Kasir A (proses utama)');
        } catch (DraftLockedException) {
            $succeededA = false;
        }

        $result = $processB->wait();
        $this->assertTrue($result->successful(), 'Subprocess B gagal: '.$result->errorOutput());
        $succeededB = str_contains($result->output(), 'HELD');
        $rejectedB = str_contains($result->output(), 'REJECTED');
        $this->assertTrue($succeededB xor $rejectedB, 'Subprocess B harus melaporkan tepat satu hasil (HELD atau REJECTED).');

        // Bukti utama: TEPAT SATU dari kedua percobaan yang berhasil --
        // tidak pernah keduanya (yang berarti dua kasir sama-sama diberi
        // lampu hijau mengedit draft yang sama tanpa saling tahu), dan
        // tidak pernah tidak ada sama sekali (draft harus benar-benar
        // terpegang oleh salah satu).
        $this->assertTrue(
            $succeededA xor $succeededB,
            "Tepat satu hold() yang boleh berhasil. A berhasil={$this->boolStr($succeededA)}, B berhasil={$this->boolStr($succeededB)}.",
        );

        // Baris `drafts` di database benar-benar konsisten dengan siapa
        // yang menang -- bukan cuma nilai balik PHP yang kebetulan benar.
        $winnerId = $succeededA ? $this->userA->id : $this->userB->id;
        $this->assertSame($winnerId, $this->draft->fresh()->held_by_user_id);
    }

    private function boolStr(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
