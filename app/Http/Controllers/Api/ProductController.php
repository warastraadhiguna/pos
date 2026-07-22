<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductStockResource;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Support\SyncWatermark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Product catalog for the mobile client to cache offline — including
     * barcode, tax rate, category, and BOM components (for display only;
     * the real stock deduction always happens server-side when the sale is
     * synced, exactly like the web Kasir).
     *
     * Supports incremental sync via ?updated_since=<ISO8601>: omit it for a
     * full pull, or pass the `synced_at` a previous call returned to get
     * only what changed. Returns every matching product regardless of
     * is_active, so the client can also learn about products that were
     * deactivated since — filtering for display is the client's job.
     *
     * `meta.ppn_active` piggybacks the global PPN switch onto this same
     * response so an offline-first client refreshes it on the same sync
     * cadence it already uses for products — no separate endpoint. Prices
     * (`sell_price`) are tax-inclusive; this flag plus each product's own
     * `tax_rate` are what the client needs to estimate a receipt total
     * offline. The server's own recomputation at sync time is always what
     * actually posts to the books — the client's total is an estimate,
     * same trust model as the web Kasir screen.
     *
     * `meta.product_display_mode` rides along the same way — 'all' (grid
     * penuh) or 'search_only' (grid kosong sampai kasir mengetik). Murni
     * preferensi tampilan, tidak memengaruhi data produk itu sendiri.
     *
     * `meta.store_name`/`store_address`/`store_phone`/`receipt_footer`/
     * `show_stock_on_button`/`show_product_image`/`payment_quick_amounts`
     * piggyback the same way — semuanya preferensi/identitas ringan yang
     * jarang berubah, jadi cukup ikut sync produk yang sudah ada, bukan
     * endpoint terpisah. BEDA dari stok itu sendiri (lihat stock() di
     * bawah) yang berubah setiap transaksi dan sengaja endpoint TERPISAH
     * non-incremental.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['updated_since' => ['nullable', 'date']]);
        $syncedAt = SyncWatermark::now();

        $products = SyncWatermark::applyIncrementalFilter(
            Product::with(['taxRate', 'productCategory', 'components.uom']),
            $validated['updated_since'] ?? null,
        )->orderBy('id')->get();

        $setting = CompanySetting::current();

        return ProductResource::collection($products)
            ->additional(['meta' => [
                'synced_at' => $syncedAt->toIso8601String(),
                'ppn_active' => $setting->ppn_active,
                'product_display_mode' => $setting->product_display_mode,
                'store_name' => $setting->store_name,
                'store_address' => $setting->store_address,
                'store_phone' => $setting->store_phone,
                'receipt_footer' => $setting->receipt_footer,
                'show_stock_on_button' => $setting->show_stock_on_button,
                'show_product_image' => $setting->show_product_image,
                'payment_quick_amounts' => $setting->payment_quick_amounts,
            ]]);
    }

    /**
     * Berapa banyak tiap Product bisa dijual SAAT INI, dari stok komponen
     * BOM-nya — snapshot penuh, BUKAN incremental (sama alasannya dengan
     * Api\ItemController::stock(): stok berubah di setiap transaksi dari
     * perangkat manapun, "apa yang berubah sejak X" tidak berarti apa-apa
     * untuk ini; klien cukup refresh berkala). Murni advisory (badge di
     * tombol kasir) — pengurangan stok sesungguhnya selalu terjadi di
     * server saat sale disinkronkan, identik jalur web Kasir.
     *
     * Lihat InventoryService::producibleQtyForProducts() untuk rumus
     * (min atas komponen BOM, komponen cost_only dilewati, null berarti
     * "tidak dibatasi stok" bukan 0).
     */
    public function stock(Request $request): AnonymousResourceCollection
    {
        $warehouseId = (int) $request->query('warehouse_id', 1);
        $warehouse = Warehouse::findOrFail($warehouseId);
        $asOf = SyncWatermark::now();

        $products = Product::with(['components.item', 'components.uom'])
            ->where('is_active', true)
            ->get();

        $producible = $this->inventory->producibleQtyForProducts($products, $warehouse);

        $rows = $products->map(fn (Product $product) => [
            'product_id' => $product->id,
            'producible_qty' => $producible[$product->id],
        ]);

        return ProductStockResource::collection($rows)
            ->additional(['meta' => ['as_of' => $asOf->toIso8601String()]]);
    }
}
