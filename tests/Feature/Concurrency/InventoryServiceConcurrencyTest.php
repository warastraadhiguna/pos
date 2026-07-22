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
}
