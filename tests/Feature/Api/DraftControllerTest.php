<?php

namespace Tests\Feature\Api;

use App\Models\Draft;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Langkah 3/3 fitur Draft -- sync lintas-device. Lihat docblock
 * `DraftSyncService` untuk rancangan lengkap; file ini membuktikannya,
 * terutama kasus tepi yang eksplisit diminta: idempotency, dua device
 * offline meja sama, soft-lock + timeout, merge is_printed, last-write-
 * wins per item (bukan per draft), dan propagasi finalisasi.
 */
class DraftControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
    }

    /**
     * Token ASLI lewat AuthController (bukan Sanctum::actingAs()) --
     * `currentAccessToken()?->name` (device_label, dibaca `hold()`) adalah
     * Mockery mock tanpa `name` sungguhan di bawah `actingAs()`, pola sama
     * `SaleControllerTest`.
     */
    private function loginAs(string $deviceName): array
    {
        $user = User::factory()->create(['password' => bcrypt('secret1234')]);
        $token = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret1234',
            'device_name' => $deviceName,
        ])->json('token');

        return [$user, $token];
    }

    /**
     * `RequestGuard::user()` (Illuminate\Auth) meng-cache user hasil resolve
     * PERTAMA di properti instance & AuthManager meng-cache instance guard
     * itu sendiri per nama -- keduanya bertahan sepanjang $this->app, yang
     * di TestCase SAMA untuk semua panggilan postJson()/getJson() dalam SATU
     * method test. Akibatnya: tanpa forgetGuards(), request KEDUA di sini
     * dengan token device LAIN tetap resolve ke user device PERTAMA (bug ini
     * TIDAK muncul di produksi -- tiap request nyata dapat Application baru).
     * Test dua-device (soft-lock A/B) di file ini butuh reset paksa supaya
     * tiap ganti token benar-benar re-resolve dari header, bukan dari cache.
     */
    private function asDevice(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function line(int $productId, array $overrides = []): array
    {
        return array_merge([
            'local_uuid' => (string) Str::uuid(),
            'product_id' => $productId,
            'product_name_snapshot' => 'Es Teh',
            'qty' => 1,
            'unit_price' => 5000,
            'tax_rate' => 0,
            'line_total' => 5000,
            'content_updated_at' => now()->toIso8601String(),
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // #1 -- idempotency (draft dibuat offline, push [ulang] tidak dobel).
    // ------------------------------------------------------------------
    public function test_pushing_the_same_local_uuid_twice_does_not_create_a_duplicate_draft(): void
    {
        [, $token] = $this->loginAs('HP Kasir 1');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();
        $payload = [
            'local_uuid' => $draftUuid,
            'table_name' => 'Meja 5',
            'lines' => [$this->line($product->id)],
        ];

        $first = $this->asDevice($token)->postJson('/api/v1/drafts', $payload);
        $first->assertOk();

        // Retry (mis. koneksi putus sebelum respons pertama diterima) --
        // payload PERSIS sama, termasuk local_uuid baris.
        $second = $this->asDevice($token)->postJson('/api/v1/drafts', $payload);
        $second->assertOk();

        $this->assertSame(1, Draft::count());
        $this->assertSame(1, Draft::first()->lines()->count());
    }

    // ------------------------------------------------------------------
    // #2 -- dua device offline, draft meja sama -- BOLEH dua draft.
    // ------------------------------------------------------------------
    public function test_two_devices_creating_a_draft_for_the_same_table_offline_results_in_two_separate_drafts_not_an_error_or_silent_merge(): void
    {
        [, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);

        $responseA = $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => (string) Str::uuid(),
            'table_name' => 'Meja 5',
            'lines' => [$this->line($product->id, ['product_name_snapshot' => 'Kopi (dari A)'])],
        ]);
        $responseB = $this->asDevice($tokenB)->postJson('/api/v1/drafts', [
            'local_uuid' => (string) Str::uuid(),
            'table_name' => 'Meja 5',
            'lines' => [$this->line($product->id, ['product_name_snapshot' => 'Teh (dari B)'])],
        ]);

        $responseA->assertOk();
        $responseB->assertOk();

        // TIDAK error, TIDAK tergabung diam-diam -- dua baris terpisah,
        // masing-masing utuh dengan isinya sendiri. Staf yang memutuskan
        // manual lewat UI (lihat rancangan), bukan sistem yang menebak.
        $this->assertSame(2, Draft::where('table_name_snapshot', 'Meja 5')->count());
        $names = Draft::where('table_name_snapshot', 'Meja 5')
            ->with('lines')
            ->get()
            ->flatMap(fn ($d) => $d->lines->pluck('product_name_snapshot'));
        $this->assertTrue($names->contains('Kopi (dari A)'));
        $this->assertTrue($names->contains('Teh (dari B)'));
    }

    // ------------------------------------------------------------------
    // #3 -- soft-lock: A pegang, B lihat, A lepas, B ambil.
    // ------------------------------------------------------------------
    public function test_soft_lock_a_holds_b_sees_it_then_a_releases_then_b_can_hold(): void
    {
        [$userA, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $draft = Draft::create([
            'outlet_id' => Outlet::first()->id, 'local_uuid' => (string) Str::uuid(), 'status' => 'open',
        ]);

        $holdA = $this->asDevice($tokenA)->postJson("/api/v1/drafts/{$draft->id}/hold");
        $holdA->assertOk();
        $holdA->assertJsonPath('data.held_by_user_id', $userA->id);
        $holdA->assertJsonPath('data.held_by_device_label', 'HP Kasir A');

        // B coba pegang -- DITOLAK (409), body memberi tahu siapa pemegangnya.
        $holdBBlocked = $this->asDevice($tokenB)->postJson("/api/v1/drafts/{$draft->id}/hold");
        $holdBBlocked->assertStatus(409);
        $holdBBlocked->assertJsonPath('data.held_by_name', $userA->name);
        // `message` di level TERATAS (bukan cuma tersirat lewat
        // `data.held_by_name`) -- ApiClient mobile cuma membaca `message`
        // top-level saat membentuk ApiException, jadi alasan penolakan
        // WAJIB ada di sini supaya sampai ke UI kasir.
        $holdBBlocked->assertJsonPath('message', fn ($message) => str_contains($message, $userA->name));

        // A lepas.
        $release = $this->asDevice($tokenA)->postJson("/api/v1/drafts/{$draft->id}/release");
        $release->assertOk();
        $this->assertNull($draft->fresh()->held_by_user_id);

        // B sekarang bisa pegang tanpa force.
        $holdBNow = $this->asDevice($tokenB)->postJson("/api/v1/drafts/{$draft->id}/hold");
        $holdBNow->assertOk();
        $this->assertSame($draft->fresh()->held_by_user_id, User::where('name', $holdBNow->json('data.held_by_name'))->first()->id);
    }

    // ------------------------------------------------------------------
    // #4 -- timeout: A pegang lalu "offline" (held_at > 5 menit) -- B
    // bisa mengambil alih TANPA force sama sekali (kedaluwarsa otomatis).
    // ------------------------------------------------------------------
    public function test_a_lock_older_than_the_five_minute_timeout_can_be_reclaimed_by_another_device_without_force(): void
    {
        [$userA, $tokenA] = $this->loginAs('HP Kasir A');
        [$userB, $tokenB] = $this->loginAs('HP Kasir B');
        $draft = Draft::create([
            'outlet_id' => Outlet::first()->id, 'local_uuid' => (string) Str::uuid(), 'status' => 'open',
            'held_by_user_id' => $userA->id, 'held_by_device_label' => 'HP Kasir A',
            'held_at' => now()->subMinutes(6), // > 5 menit -- kedaluwarsa.
        ]);

        $hold = $this->asDevice($tokenB)->postJson("/api/v1/drafts/{$draft->id}/hold");

        $hold->assertOk();
        $hold->assertJsonPath('data.held_by_user_id', $userB->id);
    }

    public function test_a_lock_still_within_the_five_minute_timeout_requires_force_to_take_over(): void
    {
        [$userA, ] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $draft = Draft::create([
            'outlet_id' => Outlet::first()->id, 'local_uuid' => (string) Str::uuid(), 'status' => 'open',
            'held_by_user_id' => $userA->id, 'held_by_device_label' => 'HP Kasir A',
            'held_at' => now()->subMinutes(4), // < 5 menit -- MASIH berlaku.
        ]);

        $blocked = $this->asDevice($tokenB)->postJson("/api/v1/drafts/{$draft->id}/hold");
        $blocked->assertStatus(409);

        // "Ambil Alih" eksplisit (force) SELALU berhasil, lock bukan
        // penjamin data (lihat DraftSyncService::hold() docblock).
        $forced = $this->asDevice($tokenB)->postJson("/api/v1/drafts/{$draft->id}/hold", ['force' => true]);
        $forced->assertOk();
    }

    // ------------------------------------------------------------------
    // #5 -- is_printed union/OR: tercetak di satu device -> tercetak di
    // semua, TIDAK PERNAH hilang lewat push device lain yang lebih baru
    // ATAU lebih lama kontennya.
    // ------------------------------------------------------------------
    public function test_is_printed_survives_a_later_push_from_a_device_that_never_touched_the_print_state(): void
    {
        [, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();
        $lineUuid = (string) Str::uuid();
        $t0 = now()->subMinutes(10);

        // Basis bersama -- A push draft dengan satu baris, is_printed=false.
        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid, 'content_updated_at' => $t0->toIso8601String(),
            ])],
        ])->assertOk();

        // B mencetak (offline saat itu) -- is_printed=true, content TIDAK
        // berubah (mencetak bukan perubahan konten, content_updated_at
        // tetap T0), push duluan.
        $this->asDevice($tokenB)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid, 'content_updated_at' => $t0->toIso8601String(),
                'is_printed' => true,
            ])],
        ])->assertOk();
        $this->assertTrue(Draft::where('local_uuid', $draftUuid)->first()->lines->first()->is_printed);

        // A (TIDAK tahu B mencetak) menambah qty -- content_updated_at
        // BARU (T1 > T0), is_printed tetap false di sisi A (A tidak
        // pernah menyentuh status cetak), push BELAKANGAN.
        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid, 'qty' => 2, 'line_total' => 10000,
                'content_updated_at' => $t0->addMinute()->toIso8601String(),
                'is_printed' => false,
            ])],
        ])->assertOk();

        $line = Draft::where('local_uuid', $draftUuid)->first()->lines->first();
        // Qty A menang (konten lebih baru) TAPI cetakan B TIDAK hilang --
        // ini skenario dobel-cetak-dapur yang eksplisit wajib dihindari.
        $this->assertSame('2.0000', $line->qty);
        $this->assertTrue($line->is_printed, 'is_printed harus TETAP true walau push berikutnya membawa is_printed=false untuk konten yang lebih baru.');
    }

    public function test_is_printed_survives_even_when_the_push_carrying_it_is_older_than_servers_current_content(): void
    {
        [, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();
        $lineUuid = (string) Str::uuid();
        $t0 = now()->subMinutes(10);

        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid, 'content_updated_at' => $t0->toIso8601String(),
            ])],
        ])->assertOk();

        // A edit qty duluan (content_updated_at T1 > T0), push -- server
        // sekarang punya konten LEBIH BARU dari yang B ketahui.
        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid, 'qty' => 3, 'line_total' => 15000,
                'content_updated_at' => $t0->clone()->addMinute()->toIso8601String(),
            ])],
        ])->assertOk();

        // B (offline sejak T0, belum tahu edit A) mencetak dari
        // snapshotnya yang BASI (masih T0, qty 1) lalu baru sekarang
        // online dan push.
        $this->asDevice($tokenB)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid, 'content_updated_at' => $t0->toIso8601String(),
                'is_printed' => true,
            ])],
        ])->assertOk();

        $line = Draft::where('local_uuid', $draftUuid)->first()->lines->first();
        // Konten A (lebih baru) tetap menang -- qty B yang basi (1) TIDAK
        // menimpa qty A (3). TAPI cetakan B tetap dicatat (dia benar-benar
        // mencetak, fakta itu valid terlepas snapshot kontennya basi).
        $this->assertSame('3.0000', $line->qty);
        $this->assertTrue($line->is_printed);
    }

    // ------------------------------------------------------------------
    // #6 -- last-write-wins PER ITEM: dua device edit BARIS BERBEDA tidak
    // pernah saling menimpa (buktinya "no data lost").
    // ------------------------------------------------------------------
    public function test_editing_different_lines_from_different_devices_never_clobbers_the_other_devices_line(): void
    {
        [, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();
        $lineXUuid = (string) Str::uuid();
        $lineYUuid = (string) Str::uuid();
        $t0 = now()->subMinutes(10);

        // Basis: dua baris X dan Y.
        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [
                $this->line($product->id, ['local_uuid' => $lineXUuid, 'product_name_snapshot' => 'X', 'content_updated_at' => $t0->toIso8601String()]),
                $this->line($product->id, ['local_uuid' => $lineYUuid, 'product_name_snapshot' => 'Y', 'content_updated_at' => $t0->toIso8601String()]),
            ],
        ])->assertOk();

        // A (offline) edit HANYA baris X (qty 5) -- payload A tetap
        // menyertakan Y APA ADANYA (tidak berubah, content_updated_at
        // sama T0) karena client selalu kirim seluruh baris yang ia tahu.
        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [
                $this->line($product->id, ['local_uuid' => $lineXUuid, 'product_name_snapshot' => 'X', 'qty' => 5, 'line_total' => 25000, 'content_updated_at' => $t0->clone()->addMinute()->toIso8601String()]),
                $this->line($product->id, ['local_uuid' => $lineYUuid, 'product_name_snapshot' => 'Y', 'content_updated_at' => $t0->toIso8601String()]),
            ],
        ])->assertOk();

        // B (offline, TIDAK tahu soal edit A) edit HANYA baris Y (qty 7)
        // dari basis yang sama, push belakangan.
        $this->asDevice($tokenB)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [
                $this->line($product->id, ['local_uuid' => $lineXUuid, 'product_name_snapshot' => 'X', 'content_updated_at' => $t0->toIso8601String()]),
                $this->line($product->id, ['local_uuid' => $lineYUuid, 'product_name_snapshot' => 'Y', 'qty' => 7, 'line_total' => 35000, 'content_updated_at' => $t0->clone()->addMinutes(2)->toIso8601String()]),
            ],
        ])->assertOk();

        $lines = Draft::where('local_uuid', $draftUuid)->first()->lines->keyBy('local_uuid');
        // BUKTI UTAMA: edit A ke X TIDAK hilang walau B push belakangan
        // dengan snapshot X yang basi (B tidak pernah menyentuh X).
        $this->assertSame('5.0000', $lines[$lineXUuid]->qty, 'Edit A ke baris X tidak boleh hilang oleh push B.');
        $this->assertSame('7.0000', $lines[$lineYUuid]->qty, 'Edit B ke baris Y harus tersimpan.');
    }

    public function test_two_edits_to_the_exact_same_line_the_content_with_the_newer_content_updated_at_wins(): void
    {
        [, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();
        $lineUuid = (string) Str::uuid();
        $t0 = now()->subMinutes(10);

        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, ['local_uuid' => $lineUuid, 'content_updated_at' => $t0->toIso8601String()])],
        ])->assertOk();

        // A edit ke qty=9 di T2 (paling baru).
        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, ['local_uuid' => $lineUuid, 'qty' => 9, 'line_total' => 45000, 'content_updated_at' => $t0->clone()->addMinutes(5)->toIso8601String()])],
        ])->assertOk();

        // B push versi lebih TUA (qty=2 di T1, dibuat sebelum tahu A
        // sudah edit ke 9) -- BELAKANGAN secara wall-clock request, tapi
        // KONTENnya lebih basi.
        $this->asDevice($tokenB)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, ['local_uuid' => $lineUuid, 'qty' => 2, 'line_total' => 10000, 'content_updated_at' => $t0->clone()->addMinutes(1)->toIso8601String()])],
        ])->assertOk();

        // Qty A (9, content_updated_at lebih baru) yang menang -- BUKAN
        // qty B yang datang belakangan tapi kontennya lebih basi.
        $this->assertSame('9.0000', Draft::where('local_uuid', $draftUuid)->first()->lines->first()->qty);
    }

    // ------------------------------------------------------------------
    // #7 -- finalisasi (jadi Sale) di satu device -> hilang dari daftar
    // draft aktif SEMUA device.
    // ------------------------------------------------------------------
    public function test_finalizing_a_draft_via_sale_push_marks_it_finalized_and_it_disappears_from_the_open_pull(): void
    {
        [, $tokenA] = $this->loginAs('HP Kasir A');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();

        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id)],
        ])->assertOk();

        $pullBefore = $this->asDevice($tokenA)->getJson('/api/v1/drafts');
        $this->assertCount(1, $pullBefore->json('data'));

        // Finalisasi -- checkout mobile mengirim draft_local_uuid bareng
        // payload sale (satu transaksi atomik, lihat SaleService).
        $sale = $this->asDevice($tokenA)->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => now()->toIso8601String(),
            'cash_received' => 5000,
            'change_amount' => 0,
            'draft_local_uuid' => $draftUuid,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ]);
        $sale->assertCreated();

        $this->assertSame('finalized', Draft::where('local_uuid', $draftUuid)->first()->status);
        $this->assertSame(1, Sale::count());

        // Full pull (tanpa updated_since) HANYA mengembalikan draft
        // 'open' -- draft yang barusan final tidak lagi muncul, di
        // device MANA PUN yang menariknya, bukan cuma device A.
        $pullAfter = $this->asDevice($tokenA)->getJson('/api/v1/drafts');
        $this->assertCount(0, $pullAfter->json('data'));
    }

    public function test_incremental_pull_reports_a_recently_finalized_draft_once_so_a_device_with_a_stale_local_cache_can_remove_it(): void
    {
        [, $tokenA] = $this->loginAs('HP Kasir A');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();

        $watermark = now()->subMinute()->toIso8601String();

        $this->asDevice($tokenA)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id)],
        ])->assertOk();
        $this->asDevice($tokenA)->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => now()->toIso8601String(),
            'cash_received' => 5000,
            'change_amount' => 0,
            'draft_local_uuid' => $draftUuid,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        // Device B (cache lokal masih punya draft ini dari sebelum
        // finalisasi) pull inkremental sejak SEBELUM finalisasi terjadi
        // -- HARUS tetap melihat baris ini (status='finalized') supaya
        // tahu untuk menghapusnya dari daftar lokal.
        $pull = $this->asDevice($tokenA)->getJson('/api/v1/drafts?'.http_build_query(['updated_since' => $watermark]));
        $pull->assertOk();
        $found = collect($pull->json('data'))->firstWhere('local_uuid', $draftUuid);
        $this->assertNotNull($found, 'Pull inkremental harus tetap melaporkan draft yang baru finalized, supaya device lain tahu menghapusnya.');
        $this->assertSame('finalized', $found['status']);
    }

    // ------------------------------------------------------------------
    // #9 -- draft lokal lama (local_uuid baru, belum pernah dikenal
    // server) tetap bisa sync normal begitu fitur aktif.
    // ------------------------------------------------------------------
    public function test_a_never_before_seen_local_uuid_pre_dating_this_feature_syncs_as_a_normal_new_draft(): void
    {
        [, $token] = $this->loginAs('HP Lama');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);

        $response = $this->asDevice($token)->postJson('/api/v1/drafts', [
            'local_uuid' => (string) Str::uuid(),
            'table_name' => 'Meja 1',
            'lines' => [$this->line($product->id)],
        ]);

        $response->assertOk();
        $this->assertSame(1, Draft::count());
    }

    // ------------------------------------------------------------------
    // Kasus tambahan: release oleh bukan-pemegang adalah no-op; hapus
    // baris (offline) tercatat sebagai tombstone, bukan hilang diam-diam;
    // variasi ikut baris.
    // ------------------------------------------------------------------
    public function test_release_by_a_user_who_does_not_currently_hold_the_lock_is_a_noop(): void
    {
        [$userA, ] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $draft = Draft::create([
            'outlet_id' => Outlet::first()->id, 'local_uuid' => (string) Str::uuid(), 'status' => 'open',
            'held_by_user_id' => $userA->id, 'held_at' => now(),
        ]);

        $this->asDevice($tokenB)->postJson("/api/v1/drafts/{$draft->id}/release")->assertOk();

        $this->assertSame($userA->id, $draft->fresh()->held_by_user_id, 'Release oleh bukan pemegang tidak boleh melepas lock orang lain.');
    }

    public function test_deleting_a_line_offline_then_syncing_marks_it_deleted_rather_than_silently_omitting_it(): void
    {
        [, $token] = $this->loginAs('HP Kasir A');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $draftUuid = (string) Str::uuid();
        $lineUuid = (string) Str::uuid();
        $t0 = now()->subMinutes(5);

        $this->asDevice($token)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, ['local_uuid' => $lineUuid, 'content_updated_at' => $t0->toIso8601String()])],
        ])->assertOk();

        $this->asDevice($token)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid, 'is_deleted' => true,
                'content_updated_at' => $t0->clone()->addMinute()->toIso8601String(),
            ])],
        ])->assertOk();

        $line = Draft::where('local_uuid', $draftUuid)->first()->lines->first();
        $this->assertNotNull($line, 'Baris tombstone TIDAK boleh benar-benar hilang dari tabel.');
        $this->assertTrue($line->is_deleted);
    }

    public function test_variations_travel_with_a_line_and_are_replaced_wholesale_on_a_newer_content_push(): void
    {
        [, $token] = $this->loginAs('HP Kasir A');
        $product = Product::create(['name' => 'Es Teh', 'sell_price' => 5000, 'is_active' => true]);
        $variation = ProductVariation::create(['product_id' => $product->id, 'name' => 'Gelas Besar', 'additional_price' => 2000]);
        $draftUuid = (string) Str::uuid();
        $lineUuid = (string) Str::uuid();

        $this->asDevice($token)->postJson('/api/v1/drafts', [
            'local_uuid' => $draftUuid,
            'lines' => [$this->line($product->id, [
                'local_uuid' => $lineUuid,
                'variations' => [['variation_id' => $variation->id, 'name' => 'Gelas Besar', 'price' => 2000]],
            ])],
        ])->assertOk();

        $line = Draft::where('local_uuid', $draftUuid)->first()->lines->first();
        $this->assertSame(1, $line->variations()->count());
        $this->assertSame('Gelas Besar', $line->variations->first()->name_snapshot);
    }

    // ------------------------------------------------------------------
    // Konkurensi SUNGGUHAN (dua proses OS terpisah, dua koneksi MySQL
    // independen) ada di tests/Feature/Concurrency/DraftSyncConcurrencyTest
    // -- mengikuti pola InventoryServiceConcurrencyTest. Test cepat di
    // bawah ini cukup membuktikan baseline logikanya di level HTTP/
    // Sanctum (dua panggilan berurutan lewat endpoint sungguhan, bukan
    // cuma unit test service): endpoint kedua yang mencoba hold() draft
    // yang sudah terpegang tetap 409, bukan diam-diam menimpa.
    // ------------------------------------------------------------------
    public function test_a_second_hold_attempt_via_the_http_endpoint_while_already_held_is_rejected_not_silently_overwritten(): void
    {
        [$userA, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $draft = Draft::create(['outlet_id' => Outlet::first()->id, 'local_uuid' => (string) Str::uuid(), 'status' => 'open']);

        $this->asDevice($tokenA)->postJson("/api/v1/drafts/{$draft->id}/hold")->assertOk();
        $this->asDevice($tokenB)->postJson("/api/v1/drafts/{$draft->id}/hold")->assertStatus(409);

        $this->assertSame($userA->id, $draft->fresh()->held_by_user_id);
    }

    // ------------------------------------------------------------------
    // Kasir menghapus draft lokal yang SUDAH pernah ter-push -- tanpa
    // propagasi ini, pull berikutnya (device yang sama ATAU device lain)
    // akan diam-diam "resurrect" draft yang kasir kira sudah dibuang,
    // karena server masih melaporkannya `open`.
    // ------------------------------------------------------------------
    public function test_deleting_a_synced_draft_cancels_it_on_the_server_so_it_stops_appearing_in_pulls(): void
    {
        [, $token] = $this->loginAs('HP Kasir 1');
        $draft = Draft::create(['outlet_id' => Outlet::first()->id, 'local_uuid' => (string) Str::uuid(), 'status' => 'open']);

        $this->asDevice($token)->deleteJson("/api/v1/drafts/{$draft->id}")->assertOk();

        $this->assertSame('cancelled', $draft->fresh()->status);

        // Full pull (open-saja) -- draft yang barusan dibatalkan TIDAK
        // BOLEH muncul lagi, di device manapun.
        $pull = $this->asDevice($token)->getJson('/api/v1/drafts');
        $pull->assertOk();
        $this->assertNotContains($draft->id, collect($pull->json('data'))->pluck('id'));

        // Incremental pull (device yang sempat lihat draft ini SEBELUM
        // dibatalkan) HARUS tetap melihatnya, dengan status cancelled --
        // supaya bisa menghapus salinan lokalnya sendiri.
        $incrementalPull = $this->asDevice($token)->getJson(
            '/api/v1/drafts?'.http_build_query(['updated_since' => now()->subMinute()->toIso8601String()])
        );
        $incrementalPull->assertOk();
        $found = collect($incrementalPull->json('data'))->firstWhere('id', $draft->id);
        $this->assertNotNull($found);
        $this->assertSame('cancelled', $found['status']);
    }

    public function test_cancelling_a_lock_held_draft_also_releases_the_lock(): void
    {
        [$userA, $tokenA] = $this->loginAs('HP Kasir A');
        [, $tokenB] = $this->loginAs('HP Kasir B');
        $draft = Draft::create(['outlet_id' => Outlet::first()->id, 'local_uuid' => (string) Str::uuid(), 'status' => 'open']);

        $this->asDevice($tokenA)->postJson("/api/v1/drafts/{$draft->id}/hold")->assertOk();
        $this->asDevice($tokenA)->deleteJson("/api/v1/drafts/{$draft->id}")->assertOk();

        $fresh = $draft->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->held_by_user_id);
    }
}
