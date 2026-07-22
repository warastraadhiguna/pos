<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Sale;
use App\Models\User;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleControllerTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private Warehouse $warehouse;

    private Uom $pcs;

    private Account $persediaanAccount;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->inventory = new InventoryService();
        $this->warehouse = Warehouse::first();
        $this->pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->persediaanAccount = Account::where('code', '1-1200')->firstOrFail();

        // Token asli lewat AuthController, bukan Sanctum::actingAs() —
        // currentAccessToken() di bawah Sanctum::actingAs() adalah Mockery
        // mock tanpa `name` sungguhan, jadi tidak bisa dipakai untuk
        // menguji device_label yang membaca nama token yang sebenarnya.
        $this->user = User::factory()->create(['password' => bcrypt('secret1234')]);
        $token = $this->postJson('/api/v1/login', [
            'email' => $this->user->email,
            'password' => 'secret1234',
            'device_name' => 'Kasir HP Budi',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Mobile TIDAK mengirim field ini hari ini (client belum diperbarui
     * untuk mengirim productNameSnapshot lokalnya) -- tapi endpoint sudah
     * siap menerima & menyimpannya apa adanya kalau client mengirimkannya,
     * TANPA menimpanya dengan lookup nama produk saat ini. Ini satu-satunya
     * cara benar menangani produk yang di-rename SELAMA device kasir sedang
     * offline (lihat SaleService::createSaleLine()).
     */
    public function test_posting_a_sale_with_a_client_supplied_product_name_stores_it_verbatim(): void
    {
        [, $product] = $this->makeWidgetProduct();

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000, 'product_name' => 'Nama Saat Offline'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.lines.0.product_name', 'Nama Saat Offline');

        $product->update(['name' => 'Nama Berubah Setelah Sync']);
        $sale = Sale::firstOrFail();
        $this->assertSame('Nama Saat Offline', $sale->lines->first()->fresh()->product_name);
    }

    /**
     * Mobile hari ini tidak mengirim product_name sama sekali -- server
     * jatuh ke nama produk saat ini (satu-satunya pilihan yang tersedia
     * tanpa field ini, dan benar untuk jalur real-time / sync tanpa jeda
     * waktu yang berarti).
     */
    public function test_posting_a_sale_without_a_product_name_falls_back_to_the_current_product_name(): void
    {
        [, $product] = $this->makeWidgetProduct();

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.lines.0.product_name', 'Widget Product');
    }

    public function test_posting_a_sale_deducts_stock_and_returns_the_created_sale(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('meta.replayed', false);
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.created_by_user_id', $this->user->id);
        $response->assertJsonPath('data.device_label', 'Kasir HP Budi');
        $this->assertSame('98.0000', $this->inventory->currentStock($item, $this->warehouse));
        $this->assertSame(1, Sale::count());
    }

    public function test_retrying_the_same_local_uuid_does_not_duplicate_the_sale(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $payload = [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'payment_method' => 'cash',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ];

        // Percobaan pertama: sale baru, bukan replay.
        $first = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json();
        $this->assertFalse($first['meta']['replayed']);

        // Skenario retry HP kasir setelah koneksi putus: request yang sama
        // persis dikirim ulang dengan local_uuid yang sama. Tetap 201, data
        // identik, cuma meta.replayed yang beda — HP boleh mengabaikannya.
        $second = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json();
        $this->assertTrue($second['meta']['replayed']);

        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame($first['data']['grand_total'], $second['data']['grand_total']);
        $this->assertSame(1, Sale::count());
        $this->assertSame('98.0000', $this->inventory->currentStock($item, $this->warehouse));
    }

    public function test_local_uuid_unique_constraint_prevents_two_sales_with_the_same_uuid(): void
    {
        // Bukti bahwa jaring pengaman database sungguhan ada dan bukan cuma
        // asumsi: mencoba INSERT kedua dengan local_uuid yang sama pasti
        // gagal di level constraint, terlepas dari urutan permintaan HTTP —
        // inilah yang membuat dua request nyaris bersamaan tetap aman di
        // SaleService::createSale() (lihat catch QueryException di sana).
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $localUuid = (string) Str::uuid();
        $this->postJson('/api/v1/sales', [
            'local_uuid' => $localUuid,
            'date' => '2026-07-04',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);
        Sale::create([
            'outlet_id' => Outlet::first()->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-07-04',
            'local_uuid' => $localUuid,
            'subtotal' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
        ]);
    }

    public function test_status_endpoint_returns_the_sale_when_it_exists(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $localUuid = (string) Str::uuid();
        $this->postJson('/api/v1/sales', [
            'local_uuid' => $localUuid,
            'date' => '2026-07-04',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $response = $this->getJson("/api/v1/sales/{$localUuid}");

        $response->assertOk();
        $response->assertJsonPath('data.local_uuid', $localUuid);
        $response->assertJsonPath('data.status', 'completed');
    }

    public function test_status_endpoint_returns_404_when_the_sale_was_never_received(): void
    {
        $response = $this->getJson('/api/v1/sales/'.((string) Str::uuid()));

        $response->assertStatus(404);
    }

    public function test_cash_received_and_change_amount_are_validated_and_stored_correctly(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        // grand_total = 2 x 5000 = 10000. Kasir menerima Rp15.000 tunai ->
        // kembalian seharusnya Rp5.000.
        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 15000,
            'change_amount' => 5000,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.cash_received', '15000.0000');
        $response->assertJsonPath('data.change_amount', '5000.0000');

        $sale = Sale::where('local_uuid', $response->json('data.local_uuid'))->firstOrFail();
        $this->assertSame(0, bccomp($sale->cash_received, '15000', 4));
        $this->assertSame(0, bccomp($sale->change_amount, '5000', 4));
    }

    public function test_cash_received_less_than_grand_total_is_rejected(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        // grand_total = 10000, tapi kasir cuma kirim cash_received 8000 —
        // uang yang diterima tidak cukup menutup total. Server (bukan cuma
        // klien) yang menolak ini, di dalam SaleService::createSale()
        // setelah grand_total sungguhan diketahui dari baris-barisnya.
        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 8000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count(), 'Sale tidak boleh tersimpan sama sekali kalau cash_received kurang.');
    }

    public function test_change_amount_not_matching_server_recomputation_is_rejected(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        // grand_total = 10000, cash_received 15000 -> seharusnya kembalian
        // 5000, tapi klien (keliru/curang) mengirim change_amount 4000.
        // Server menghitung ulang sendiri dan menolak, tidak percaya begitu
        // saja angka dari klien.
        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 15000,
            'change_amount' => 4000,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_retrying_the_same_local_uuid_with_cash_received_stays_idempotent(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $payload = [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 15000,
            'change_amount' => 5000,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ];

        $first = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json();
        $this->assertFalse($first['meta']['replayed']);
        $this->assertSame('15000.0000', $first['data']['cash_received']);
        $this->assertSame('5000.0000', $first['data']['change_amount']);

        // Retry HP kasir persis sama (koneksi putus sebelum respons pertama
        // diterima) — HARUS tetap dianggap sukses lewat jalur idempotency
        // local_uuid yang sudah ada SEBELUM validasi cash_received/
        // change_amount yang baru ini ditambahkan, bukan divalidasi ulang.
        $second = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json();
        $this->assertTrue($second['meta']['replayed']);
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame('15000.0000', $second['data']['cash_received']);
        $this->assertSame('5000.0000', $second['data']['change_amount']);
        $this->assertSame(1, Sale::count());

        // Bukti yang lebih kuat lagi: retry ketiga dengan cash_received
        // BERBEDA (mis. HP salah hitung ulang saat retry) TETAP hanya
        // mengembalikan sale yang SUDAH tersimpan apa adanya — jalur
        // idempotency local_uuid mengembalikan lebih dulu sebelum baris
        // manapun diproses ulang, jadi payload retry yang "salah" ini
        // tidak pernah divalidasi ulang maupun menimpa data tersimpan.
        $thirdPayload = $payload;
        $thirdPayload['cash_received'] = 999999;
        $thirdPayload['change_amount'] = 999999;
        $third = $this->postJson('/api/v1/sales', $thirdPayload)->assertCreated()->json();
        $this->assertTrue($third['meta']['replayed']);
        $this->assertSame($first['data']['id'], $third['data']['id']);
        $this->assertSame('15000.0000', $third['data']['cash_received']);
        $this->assertSame('5000.0000', $third['data']['change_amount']);
        $this->assertSame(1, Sale::count());
    }

    /**
     * HP (setelah diperbaiki) mengirim waktu transaksi sebagai UTC
     * eksplisit ber-akhiran 'Z' -- ujung ke ujung lewat HTTP, bukan cuma
     * lewat SaleService langsung. Momen sungguhan: WIB 19 Juli 02:00 =
     * UTC 18 Juli 19:00, persis jam rawan yang jadi sumber Bug A.
     */
    public function test_occurred_at_from_an_explicit_utc_payload_is_returned_as_the_correct_wib_time(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-18T19:00:00.000Z',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ]);

        $response->assertCreated();
        // sales.date harus tanggal WIB (19), bukan tanggal UTC dari string aslinya (18).
        $response->assertJsonPath('data.date', '2026-07-19');
        // occurred_at diformat via ->toIso8601String() (SaleResource), yang
        // mempertahankan offset WIB objeknya sendiri -- harus terbaca jam
        // 02:00 dengan offset +07:00, bukan 19:00 (UTC mentah tak terkonversi).
        $occurredAt = $response->json('data.occurred_at');
        $this->assertStringContainsString('2026-07-19T02:00:00', $occurredAt);
        $this->assertStringContainsString('+07:00', $occurredAt);

        $sale = Sale::where('local_uuid', $response->json('data.local_uuid'))->firstOrFail();
        $this->assertSame('2026-07-19 02:00:00', $sale->occurred_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
    }

    /**
     * Idempotency HARUS tetap utuh sesudah occurred_at ditambahkan: retry
     * dengan local_uuid yang sama mengembalikan sale yang SAMA PERSIS
     * (termasuk occurred_at-nya, bukan dihitung ulang dari waktu retry).
     */
    public function test_retrying_the_same_local_uuid_returns_the_same_occurred_at_not_recomputed(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $payload = [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-18T19:00:00.000Z',
            'cash_received' => 5000,
            'change_amount' => 0,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5000]],
        ];

        $first = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json();

        // Retry "belakangan" (waktu berjalan beberapa detik) dengan
        // local_uuid sama -- occurred_at balasan harus IDENTIK dengan yang
        // pertama, bukan waktu retry ini.
        $second = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json();

        $this->assertTrue($second['meta']['replayed']);
        $this->assertSame($first['data']['occurred_at'], $second['data']['occurred_at']);
        $this->assertSame($first['data']['date'], $second['data']['date']);
        $this->assertSame(1, Sale::count());
    }

    public function test_client_user_id_matching_the_token_owner_is_accepted(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 10000,
            'change_amount' => 0,
            'client_user_id' => $this->user->id,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.created_by_user_id', $this->user->id);
        $this->assertSame(1, Sale::count());
    }

    /**
     * Skenario nyata: sale dibuat offline oleh kasir A, tapi baru ter-push
     * setelah kasir B login di HP yang sama (token B kini yang aktif).
     * client_user_id (klaim mobile: "ini punya A") tidak cocok dengan
     * pemilik token yang benar-benar mengautentikasi request (B) -- server
     * MENOLAK, tidak pernah diam-diam mengatribusikan ke B.
     */
    public function test_client_user_id_not_matching_the_token_owner_is_rejected_with_409_and_no_sale_created(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $otherUser = User::factory()->create();

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 10000,
            'change_amount' => 0,
            // Token yang mengautentikasi request ini milik $this->user,
            // TAPI klaim client_user_id menyebut user LAIN.
            'client_user_id' => $otherUser->id,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertStatus(409);
        $this->assertSame(
            0,
            Sale::count(),
            'Sale tidak boleh tersimpan sama sekali kalau client_user_id tidak cocok dengan pemilik token.',
        );
    }

    public function test_after_a_mismatch_rejection_the_correct_owner_can_push_the_same_local_uuid_successfully(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $otherUser = User::factory()->create();
        $payload = [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 10000,
            'change_amount' => 0,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ];

        // Percobaan pertama: kasir lain (B) sudah login di HP yang sama
        // sebelum sale kasir A ini sempat ter-push -> ditolak, TIDAK ada
        // baris sales yang tersimpan sama sekali (bukan cuma "gagal
        // atribusi" tapi baris tetap masuk).
        $this->postJson(
            '/api/v1/sales',
            array_merge($payload, ['client_user_id' => $otherUser->id]),
        )->assertStatus(409);
        $this->assertSame(0, Sale::count());

        // Kasir A (pemilik token asli dari setUp()) login lagi lalu push
        // ULANG local_uuid yang SAMA persis -- kali ini client_user_id
        // cocok dengan pemilik token -> sukses, atribusi benar ke A.
        $response = $this->postJson(
            '/api/v1/sales',
            array_merge($payload, ['client_user_id' => $this->user->id]),
        );
        $response->assertCreated();
        $response->assertJsonPath('meta.replayed', false);
        $response->assertJsonPath('data.created_by_user_id', $this->user->id);
        $this->assertSame(1, Sale::count());
    }

    /**
     * Kompatibilitas mundur: mobile client lama (dari sebelum client_user_id
     * ada) atau sale lama yang sudah pending sebelum fitur ini dipasang
     * tidak pernah mengirim field ini sama sekali. Tanpa apa pun untuk
     * diperiksa silang, server jatuh ke perilaku hari ini apa adanya --
     * sale ini TIDAK BOLEH nyangkut selamanya cuma karena tidak punya field
     * baru ini.
     */
    public function test_missing_client_user_id_falls_back_to_trusting_the_token_owner(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 10000,
            'change_amount' => 0,
            // 'client_user_id' sengaja TIDAK ada di payload sama sekali.
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.created_by_user_id', $this->user->id);
        $this->assertSame(1, Sale::count());
    }

    /**
     * Idempotency HARUS tetap menang atas pemeriksaan atribusi: replay
     * (local_uuid sudah ada) tidak pernah mengatribusikan apa pun yang baru,
     * jadi client_user_id yang mismatch pada RETRY tidak boleh mengubah
     * hasil sukses yang sudah ada sebelumnya jadi ditolak.
     */
    public function test_replaying_the_same_local_uuid_ignores_a_mismatched_client_user_id_on_retry(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $otherUser = User::factory()->create();
        $payload = [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 10000,
            'change_amount' => 0,
            'client_user_id' => $this->user->id,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ];

        $first = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json();
        $this->assertFalse($first['meta']['replayed']);

        // Retry local_uuid yang SAMA, tapi kali ini client_user_id SALAH
        // (mis. bug/kondisi tak terduga di klien) -- tetap harus dianggap
        // replay yang sukses, atribusi ASLI tidak berubah.
        $second = $this->postJson(
            '/api/v1/sales',
            array_merge($payload, ['client_user_id' => $otherUser->id]),
        )->assertCreated()->json();

        $this->assertTrue($second['meta']['replayed']);
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame($this->user->id, $second['data']['created_by_user_id']);
        $this->assertSame(1, Sale::count());
    }

    /**
     * Beda dari test mismatch di atas (menyebut user LAIN yang sah) -- ini
     * memakai id sembarang/tidak ada, membuktikan mekanismenya identik:
     * ditolak, bukan diam-diam diloloskan karena usernya "tidak ditemukan".
     */
    public function test_impersonation_attempt_with_an_arbitrary_user_id_is_rejected(): void
    {
        [$item, $product] = $this->makeWidgetProduct();
        $this->inventory->recordInbound($item, $this->warehouse, 100, 1000, Outlet::first(), '2026-07-01');

        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => (string) Str::uuid(),
            'date' => '2026-07-04',
            'cash_received' => 10000,
            'change_amount' => 0,
            'client_user_id' => 999999,
            'lines' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000],
            ],
        ]);

        $response->assertStatus(409);
        $this->assertSame(0, Sale::count());
    }

    public function test_invalid_payload_is_rejected_with_a_422(): void
    {
        $response = $this->postJson('/api/v1/sales', [
            'local_uuid' => 'not-a-uuid',
            'date' => '2026-07-04',
            'lines' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['local_uuid', 'lines']);
    }

    /**
     * @return array{0: Item, 1: Product}
     */
    private function makeWidgetProduct(): array
    {
        $item = Item::create([
            'sku' => 'WIDGET-API',
            'name' => 'Widget API',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->pcs->id,
            'purchase_uom_id' => $this->pcs->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->persediaanAccount->id,
        ]);

        $product = Product::create(['name' => 'Widget Product', 'sell_price' => 5000]);
        ProductComponent::create(['product_id' => $product->id, 'item_id' => $item->id, 'qty' => 1, 'uom_id' => $this->pcs->id]);

        return [$item, $product];
    }
}
