<?php

namespace App\Services;

use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReturn;
use App\Models\Item;
use App\Models\Warehouse;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Terima Titipan" & "Retur Titipan" -- fitur Konsinyasi (lihat rancangan
 * yang disetujui). Constructor SENGAJA hanya menerima `InventoryService`
 * (TANPA `PostingService`) -- secara desain method di kelas ini tidak
 * mungkin memposting jurnal apa pun, bukan sekadar konvensi.
 *
 * Alasan tidak pernah posting jurnal: `FinancialReportService::balanceSheet()`
 * murni dibaca dari `journal_lines` (dikonfirmasi langsung dari kode, tidak
 * pernah baca `stock_movements`) -- barang konsinyasi BUKAN milik toko
 * sampai benar-benar terjual, jadi Persediaan di Neraca secara sengaja
 * TIDAK boleh ikut membengkak untuk barang ini. `InventoryService::
 * recordInbound()`/`recordOutbound()` tetap dipanggil apa adanya (qty +
 * harga rata-rata berjalan tercatat benar) supaya HPP saat barang ini nanti
 * terjual (lihat SaleService) akurat sejak awal -- stock ledger di
 * `stock_movements` memang sudah didesain lepas total dari GL/jurnal.
 */
class ConsignmentService
{
    private const SCALE = 4;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array{
     *     supplier_id: int,
     *     warehouse_id: int,
     *     date: DateTimeInterface|string,
     *     notes?: ?string,
     *     lines: array<int, array{item_id: int, qty: int|float|string, unit_cost: int|float|string}>,
     * }  $data
     *
     * @throws InvalidArgumentException kalau ada baris yang item-nya bukan
     *   item konsinyasi, atau item konsinyasi itu milik supplier LAIN
     *   (bukan supplier di $data['supplier_id']).
     */
    public function receive(array $data): ConsignmentReceipt
    {
        return DB::transaction(function () use ($data) {
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);

            $receipt = new ConsignmentReceipt([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'date' => $data['date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $receipt->save();

            foreach ($data['lines'] as $lineData) {
                $item = $this->assertConsignmentItemOf($lineData['item_id'], (int) $data['supplier_id']);

                $qty = (string) $lineData['qty'];
                $unitCost = (string) $lineData['unit_cost'];

                $receipt->lines()->create([
                    'item_id' => $item->id,
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                ]);

                $this->inventory->recordInbound($item, $warehouse, $qty, $unitCost, $receipt, $data['date']);
            }

            return $receipt->fresh('lines');
        });
    }

    /**
     * @param  array{
     *     supplier_id: int,
     *     warehouse_id: int,
     *     date: DateTimeInterface|string,
     *     notes?: ?string,
     *     lines: array<int, array{item_id: int, qty: int|float|string}>,
     * }  $data
     *
     * @throws InvalidArgumentException sama seperti receive().
     */
    public function returnToOwner(array $data): ConsignmentReturn
    {
        return DB::transaction(function () use ($data) {
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);

            $return = new ConsignmentReturn([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'date' => $data['date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $return->save();

            foreach ($data['lines'] as $lineData) {
                $item = $this->assertConsignmentItemOf($lineData['item_id'], (int) $data['supplier_id']);

                $qty = (string) $lineData['qty'];

                $hpp = $this->inventory->recordOutbound($item, $warehouse, $qty, $return, $data['date']);

                $return->lines()->create([
                    'item_id' => $item->id,
                    'qty' => $qty,
                    'hpp_value' => $hpp,
                ]);
            }

            return $return->fresh('lines');
        });
    }

    private function assertConsignmentItemOf(int $itemId, int $supplierId): Item
    {
        $item = Item::findOrFail($itemId);

        if (! $item->is_consignment || (int) $item->consignment_supplier_id !== $supplierId) {
            throw new InvalidArgumentException(
                "Item [{$item->sku}] bukan item konsinyasi milik supplier #{$supplierId}."
            );
        }

        return $item;
    }
}
