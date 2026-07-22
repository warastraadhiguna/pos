<?php

namespace Tests\Feature\Concurrency;

use App\Models\Account;
use App\Models\Item;
use App\Models\Journal;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PostingService;
use App\Services\StockOpnameService;
use RuntimeException;

class StockOpnameServiceConcurrencyTest extends ConcurrencyTestCase
{
    // StockOpnameService::postAdjustmentJournal() resolves these two account
    // codes BY HARDCODED CODE (not injectable) — unlike PostingService (used
    // directly with ad-hoc test accounts in PostingServiceConcurrencyTest),
    // StockOpnameService always looks up '1-1200'/'5-2000' regardless of
    // which accounts the caller created. These must exist for ANY non-zero
    // diff to post its adjustment journal, whether the diff is correct (fix)
    // or a stale-computed bug — so this test creates them itself rather than
    // running FoundationSeeder (which is not idempotent and would leave
    // duplicate rows in the persistent pos_akuntansi_test database on every
    // re-run, since concurrency tests deliberately don't use RefreshDatabase).
    private const ACCOUNT_PERSEDIAAN_CODE = '1-1200';

    private const ACCOUNT_SELISIH_CODE = '5-2000';

    private InventoryService $inventory;

    private StockOpnameService $opnames;

    private Uom $uom;

    private Account $account;

    private Account $persediaanAccount;

    private Account $selisihAccount;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = new InventoryService();
        $this->opnames = new StockOpnameService($this->inventory, new PostingService());

        // Fixture unik, committed sungguhan (bukan RefreshDatabase) — lihat
        // ConcurrencyTestCase untuk alasannya. Dibersihkan manual di
        // tearDown() supaya tidak mencemari pos_akuntansi_test.
        $suffix = uniqid('cct_opname_');

        // Defensif: kalau run sebelumnya pernah crash sebelum tearDown()
        // sempat membersihkan, singkirkan dulu supaya create() di bawah
        // tidak gagal kena unique constraint pada code.
        Account::whereIn('code', [self::ACCOUNT_PERSEDIAAN_CODE, self::ACCOUNT_SELISIH_CODE])->delete();

        $this->persediaanAccount = Account::create([
            'code' => self::ACCOUNT_PERSEDIAAN_CODE,
            'name' => 'Concurrency Test Persediaan (hardcoded code)',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);
        $this->selisihAccount = Account::create([
            'code' => self::ACCOUNT_SELISIH_CODE,
            'name' => 'Concurrency Test Selisih Persediaan (hardcoded code)',
            'type' => 'expense',
            'normal_balance' => 'debit',
        ]);

        $this->uom = Uom::create(['code' => 'U-'.$suffix, 'name' => 'Concurrency Test Unit']);
        $this->account = Account::create([
            'code' => 'A-'.$suffix,
            'name' => 'Concurrency Test Persediaan',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);
        $this->outlet = Outlet::create(['name' => 'Concurrency Test Outlet '.$suffix]);
        $this->warehouse = Warehouse::create([
            'outlet_id' => $this->outlet->id,
            'name' => 'Concurrency Test Warehouse '.$suffix,
        ]);
        $this->item = Item::create([
            'sku' => 'SKU-'.$suffix,
            'name' => 'Concurrency Test Item',
            'costing_type' => 'stocked',
            'base_uom_id' => $this->uom->id,
            'purchase_uom_id' => $this->uom->id,
            'standard_cost' => 0,
            'inventory_account_id' => $this->account->id,
        ]);
    }

    protected function tearDown(): void
    {
        Journal::where('source_type', StockOpname::class)
            ->get()
            ->each(function (Journal $journal) {
                $journal->lines()->delete();
                $journal->delete();
            });
        StockOpname::query()->where('warehouse_id', $this->warehouse->id)->get()->each(function (StockOpname $opname) {
            $opname->lines()->delete();
            $opname->delete();
        });
        StockMovement::where('item_id', $this->item->id)->delete();
        $this->item->delete();
        $this->warehouse->delete();
        $this->outlet->delete();
        $this->account->delete();
        $this->uom->delete();
        $this->persediaanAccount->delete();
        $this->selisihAccount->delete();

        parent::tearDown();
    }

    /**
     * Skenario: opname sedang menghitung fisik (system_qty ter-snapshot 100
     * saat startOpname()), SEMENTARA goods-receipt +30 @1500 sedang
     * diproses transaksi lain secara genuinely konkuren pada item yang
     * sama. Fisik yang dihitung petugas = 130 — angka yang benar KALAU
     * goods-receipt itu ikut terhitung (100 + 30).
     *
     * Kalau postOpname() membaca stok SEBELUM mengunci (bug), ia akan
     * memakai angka basi 100 (dibaca sebelum proses A commit), menghitung
     * diff = +30, lalu menambahkan 30 LAGI di atas 130 yang sudah di-commit
     * proses A -> hasil akhir 160 (SALAH, tidak sama dengan counted_qty).
     * Kalau ia mengunci DULU baru membaca ulang (fix), diff yang benar
     * adalah 130-130=0 -> tidak ada penyesuaian sama sekali, hasil akhir
     * tetap 130 (SAMA PERSIS dengan counted_qty, sesuai jaminan yang
     * didokumentasikan di StockOpnameService::postOpname()).
     */
    public function test_postoperations_reads_live_stock_under_lock_not_a_stale_pre_lock_snapshot(): void
    {
        $date = '2026-07-11';
        $holdSeconds = 3;

        $this->inventory->recordInbound($this->item, $this->warehouse, 100, 1000, $this->outlet, $date);

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'item_ids' => [$this->item->id],
        ]);
        $line = $opname->lines->first();
        $this->assertSame(0, bccomp($line->system_qty, '100', 4));

        // Proses A ("goods-receipt konkuren") — subprocess OS sungguhan,
        // pakai ulang hidden command InventoryService yang sudah ada (tidak
        // perlu command baru untuk skenario ini). Menulis inbound +30
        // @1500, menahan lock 3 detik sebelum commit.
        $processA = $this->spawnArtisan([
            'concurrency-test:hold-inventory-lock',
            (string) $this->item->id,
            (string) $this->warehouse->id,
            '30',
            '1500',
            Outlet::class,
            (string) $this->outlet->id,
            $date,
            (string) $holdSeconds,
        ]);

        $this->waitForMarker($processA, 'LOCK_HELD');

        // Proses B ("penunggu") — proses test utama ini sendiri, memanggil
        // postOpname() secara normal. Fisik dihitung 130 (fisik sudah
        // termasuk goods-receipt A yang sedang diproses).
        $start = microtime(true);
        $posted = $this->opnames->postOpname($opname, [$line->id => '130'], $date);
        $elapsed = microtime(true) - $start;

        $result = $processA->wait();
        $this->assertTrue($result->successful(), 'Subprocess A gagal: '.$result->errorOutput());

        // Bukti #1: B benar-benar menunggu lock A, bukan kebetulan urutan.
        $this->assertGreaterThanOrEqual(
            $holdSeconds - 1,
            $elapsed,
            'postOpname() di proses B seharusnya ter-block oleh lock yang dipegang proses A.',
        );

        // Bukti #2: hasil akhir 130 (BENAR, sama dengan counted_qty) — bukan
        // 160 (yang akan terjadi kalau diff dihitung dari stok basi 100
        // yang dibaca sebelum A commit, lalu ditambahkan lagi di atas 130).
        $this->assertSame(
            0,
            bccomp($this->inventory->currentStock($this->item, $this->warehouse), '130', 4),
            'running_qty akhir harus 130 (sama dengan counted_qty) — 160 berarti diff dihitung dari stok basi.',
        );

        // Bukti #3: cuma DUA stock_movement — baseline (100@1000) + proses A
        // (+30@1500). Opname sendiri TIDAK menulis movement ketiga apa pun,
        // karena diff yang benar (dihitung dari stok fresh di dalam lock)
        // adalah nol. Kalau bug: opname menambah movement ketiga (+30 yang
        // salah), jadi 3 baris, bukan 2.
        $movements = StockMovement::where('item_id', $this->item->id)->get();
        $this->assertCount(2, $movements);

        // Bukti #4: baris opname sendiri mencatat diff_qty = 0, bukan +30.
        $postedLine = $posted->lines->firstOrFail();
        $this->assertSame(0, bccomp($postedLine->diff_qty, '0', 4));
        $this->assertSame(0, bccomp($postedLine->counted_qty, '130', 4));

        // Bukti #5: tidak ada jurnal penyesuaian yang diposting sama sekali
        // (diff nol -> $lines kosong di postAdjustmentJournal()).
        $this->assertSame(0, Journal::where('source_type', StockOpname::class)->where('source_id', $opname->id)->count());
    }

    /**
     * Skenario terpisah dari race stok di atas: dua panggilan postOpname()
     * yang genuinely konkuren untuk OPNAME YANG SAMA (mis. double-click
     * tombol posting, atau retry jaringan setelah request pertama sempat
     * terputus). Tanpa lock pada baris stock_opnames, keduanya bisa
     * sama-sama lolos cek `status !== 'draft'` sebelum salah satu commit,
     * menghasilkan penyesuaian ganda.
     */
    public function test_two_concurrent_posts_of_the_same_opname_serialize_and_the_second_sees_completed_status(): void
    {
        $date = '2026-07-11';
        $holdSeconds = 3;

        $this->inventory->recordInbound($this->item, $this->warehouse, 50, 1000, $this->outlet, $date);

        $opname = $this->opnames->startOpname([
            'warehouse_id' => $this->warehouse->id,
            'date' => $date,
            'item_ids' => [$this->item->id],
        ]);
        $line = $opname->lines->first();

        // Proses A ("penunggu status draft") — subprocess OS sungguhan,
        // hidden command baru yang meniru langkah kritis awal postOpname():
        // mengunci baris stock_opnames, cek status draft, tahan 3 detik,
        // lalu tandai completed. Tidak perlu lewat StockOpnameService penuh
        // untuk membuktikan mekanisme lock pada baris opname itu sendiri —
        // sama seperti ConcurrencyTestHoldInventoryLock tidak lewat
        // SaleService untuk membuktikan lock InventoryService.
        $processA = $this->spawnArtisan([
            'concurrency-test:hold-opname-lock',
            (string) $opname->id,
            (string) $holdSeconds,
        ]);

        $this->waitForMarker($processA, 'LOCK_HELD');

        // Proses B ("penunggu") — proses test utama, memanggil postOpname()
        // SUNGGUHAN untuk opname yang SAMA. Harus ter-block oleh lock
        // proses A, lalu setelah A commit, cek status B sendiri (di dalam
        // lock B) harus melihat 'completed' dan menolak dengan
        // RuntimeException — BUKAN lolos dan memproses penyesuaian kedua.
        // Catatan: TIDAK memakai pola try { ...; $this->fail(...); } catch
        // (RuntimeException $e) { ... } di sini dengan sengaja — PHPUnit's
        // AssertionFailedError (yang dilempar $this->fail()) TERNYATA
        // extends RuntimeException juga (Exception -> RuntimeException di
        // hierarki PHPUnit), jadi catch itu akan diam-diam menelan
        // panggilan fail()-nya sendiri kalau exception yang diharapkan
        // tidak terlempar — jebakan lulus-palsu persis yang harus dihindari.
        // Pola aman: tangkap ke variabel, assert di LUAR try/catch.
        $thrown = null;
        $start = microtime(true);
        try {
            $this->opnames->postOpname($opname, [$line->id => '999'], $date);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }
        $elapsed = microtime(true) - $start;

        $this->assertNotNull($thrown, 'Expected postOpname() proses B melempar RuntimeException (sudah diposting).');
        $this->assertStringContainsString('already been posted', $thrown->getMessage());

        $result = $processA->wait();
        $this->assertTrue($result->successful(), 'Subprocess A gagal: '.$result->errorOutput());

        // Bukti #1: B benar-benar menunggu lock A, bukan kebetulan urutan.
        $this->assertGreaterThanOrEqual(
            $holdSeconds - 1,
            $elapsed,
            'postOpname() proses B seharusnya ter-block oleh lock baris stock_opnames yang dipegang proses A.',
        );

        // Bukti #2: status akhir 'completed' dari proses A, tidak dobel-proses.
        $this->assertSame('completed', $opname->fresh()->status);

        // Bukti #3: B ditolak SEBELUM sempat masuk ke loop baris — tidak
        // ada movement baru selain baseline, meski B mengirim counted_qty
        // (999) yang jelas berbeda dari system_qty.
        $this->assertCount(1, StockMovement::where('item_id', $this->item->id)->get());
    }
}
