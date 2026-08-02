<?php

namespace Tests\Feature\Concurrency;

use App\Models\Account;
use App\Models\Item;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\InventoryService;

class InventoryServiceConcurrencyTest extends ConcurrencyTestCase
{
    private InventoryService $inventory;

    private Uom $uom;

    private Account $account;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = new InventoryService();

        // Created directly (not via FoundationSeeder) with unique, throwaway
        // identifiers, and fully deleted in tearDown() — this test commits
        // real rows (no RefreshDatabase), so anything left behind would
        // permanently pollute pos_akuntansi_test for every other test class.
        $suffix = uniqid('cct_');

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
        StockMovement::where('item_id', $this->item->id)->delete();
        $this->item->delete();
        $this->warehouse->delete();
        $this->outlet->delete();
        $this->account->delete();
        $this->uom->delete();

        parent::tearDown();
    }

    public function test_two_concurrent_inbounds_on_the_same_item_serialize_without_a_lost_update(): void
    {
        $date = '2026-07-04';
        $holdSeconds = 3;

        // Proses A ("pemegang lock") — subprocess OS sungguhan dengan
        // koneksi MySQL sendiri. Menulis inbound 100 @1000, lalu menahan
        // row lock 3 detik sebelum benar-benar commit.
        $processA = $this->spawnArtisan([
            'concurrency-test:hold-inventory-lock',
            (string) $this->item->id,
            (string) $this->warehouse->id,
            '100',
            '1000',
            Outlet::class,
            (string) $this->outlet->id,
            $date,
            (string) $holdSeconds,
        ]);

        $this->waitForMarker($processA, 'LOCK_HELD');

        // Proses B ("penunggu") — proses test utama ini sendiri, koneksi
        // Laravel normal. Ini wajib ter-block sampai A commit, karena
        // mengunci baris Item/stock_movement yang sama persis.
        $start = microtime(true);
        $this->inventory->recordInbound($this->item, $this->warehouse, 50, 2000, $this->outlet, $date);
        $elapsed = microtime(true) - $start;

        $result = $processA->wait();
        $this->assertTrue($result->successful(), 'Subprocess A gagal: '.$result->errorOutput());

        // Bukti #1: B benar-benar menunggu — durasi blocking mendekati
        // waktu A menahan lock, bukan kebetulan urutan eksekusi.
        $this->assertGreaterThanOrEqual(
            $holdSeconds - 1,
            $elapsed,
            'recordInbound() di proses B seharusnya ter-block oleh lock yang dipegang proses A.',
        );

        // Bukti #2: tidak ada movement yang hilang.
        $movements = StockMovement::where('item_id', $this->item->id)->orderBy('id')->get();
        $this->assertCount(2, $movements);

        // Bukti #3: hasil akhir benar seolah dieksekusi berurutan — bukan
        // salah satu transaksi menimpa hasil baca yang lain. Kalau lock
        // gagal, hasil yang paling mungkin adalah salah satu dari
        // qty=100/avg=1000 ATAU qty=50/avg=2000 (yang terakhir menang
        // menimpa) — BUKAN gabungan 150/1333.3333 yang benar ini.
        $this->assertSame(0, bccomp($movements[0]->running_qty, '100', 4));
        $this->assertSame(0, bccomp($movements[0]->running_average_cost, '1000', 4));
        $this->assertSame(0, bccomp($movements[1]->running_qty, '150', 4));
        $this->assertSame(0, bccomp($movements[1]->running_average_cost, '1333.3333', 4));

        $this->assertSame(0, bccomp($this->inventory->currentStock($this->item, $this->warehouse), '150', 4));
        $this->assertSame(0, bccomp($this->inventory->currentAverageCost($this->item, $this->warehouse), '1333.3333', 4));
    }

    /**
     * OUTBOUND counterpart of the inbound test above — this is the EXACT
     * primitive Variasi Berbayar Tahap 2 relies on
     * (SaleService::consumeSaleLineVariations() calls recordOutbound() to
     * consume a variation's BOM component, PERSIS the same call product BOM
     * consumption already makes a few lines above it). Proving this
     * primitive serializes two genuinely concurrent callers correctly is
     * what proves variation stock consumption is concurrency-safe, without
     * building or testing a second locking mechanism — Tahap 2 introduces
     * none.
     */
    public function test_two_concurrent_outbounds_on_the_same_item_serialize_without_a_lost_update(): void
    {
        $date = '2026-07-04';
        $holdSeconds = 3;

        // Stok awal 200 @ 500 -- ditulis di proses UTAMA, TERKOMIT sebelum
        // subprocess A dimulai, supaya A benar-benar membaca stok ini
        // (bukan stok kosong) saat dia mengunci ledger duluan.
        $this->inventory->recordInbound($this->item, $this->warehouse, 200, 500, $this->outlet, $date);

        // Proses A ("pemegang lock") -- subprocess OS sungguhan, keluarkan
        // 30 unit, lalu tahan row lock 3 detik sebelum commit.
        $processA = $this->spawnArtisan([
            'concurrency-test:hold-inventory-outbound',
            (string) $this->item->id,
            (string) $this->warehouse->id,
            '30',
            Outlet::class,
            (string) $this->outlet->id,
            $date,
            (string) $holdSeconds,
        ]);

        $this->waitForMarker($processA, 'LOCK_HELD');

        // Proses B ("penunggu") -- proses test utama ini sendiri, keluarkan
        // 20 unit. Wajib ter-block sampai A commit, karena mengunci baris
        // Item/stock_movement yang sama persis (lockLedger()).
        $start = microtime(true);
        $hppB = $this->inventory->recordOutbound($this->item, $this->warehouse, 20, $this->outlet, $date);
        $elapsed = microtime(true) - $start;

        $result = $processA->wait();
        $this->assertTrue($result->successful(), 'Subprocess A gagal: '.$result->errorOutput());

        // Bukti #1: B benar-benar menunggu.
        $this->assertGreaterThanOrEqual(
            $holdSeconds - 1,
            $elapsed,
            'recordOutbound() di proses B seharusnya ter-block oleh lock yang dipegang proses A.',
        );

        // Bukti #2: tidak ada movement yang hilang -- 1 inbound (stok awal)
        // + 2 outbound (A dan B).
        $movements = StockMovement::where('item_id', $this->item->id)->orderBy('id')->get();
        $this->assertCount(3, $movements);

        // Bukti #3: hasil akhir benar seolah dieksekusi berurutan -- BUKAN
        // salah satu outbound menimpa hasil baca yang lain (lost update
        // akan menyisakan running_qty 170 ATAU 180, bukan 150 yang benar).
        // average_cost TIDAK berubah oleh outbound (lihat docblock
        // InventoryService::recordOutbound()) -- tetap 500 di movement
        // manapun.
        $this->assertSame(0, bccomp($movements[1]->running_qty, '170', 4));
        $this->assertSame(0, bccomp($movements[1]->running_average_cost, '500', 4));
        $this->assertSame(0, bccomp($movements[2]->running_qty, '150', 4));
        $this->assertSame(0, bccomp($movements[2]->running_average_cost, '500', 4));

        // Bukti #4: HPP proses B dihitung dari average cost yang benar
        // (500), bukan angka pra-lock yang stale.
        $this->assertSame(0, bccomp($hppB, '10000', 4));

        $this->assertSame(0, bccomp($this->inventory->currentStock($this->item, $this->warehouse), '150', 4));
    }
}
