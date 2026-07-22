<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\CashAccountService;
use App\Services\InventoryService;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly InventoryService $inventory,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function index(): Response
    {
        $setting = CompanySetting::current();

        // Komponen BOM cuma perlu dimuat kalau stok memang ditampilkan --
        // query producibleQtyForProducts() tetap murah (satu batch query),
        // tapi tidak ada gunanya kalau togglenya mati.
        $products = $setting->show_stock_on_button
            ? Product::with(['taxRate', 'components.item', 'components.uom'])->where('is_active', true)->orderBy('name')->get()
            : Product::with('taxRate')->where('is_active', true)->orderBy('name')->get();

        $productStock = null;
        if ($setting->show_stock_on_button) {
            $outlet = Outlet::firstOrFail();
            $warehouse = Warehouse::where('outlet_id', $outlet->id)->firstOrFail();
            $productStock = $this->inventory->producibleQtyForProducts($products, $warehouse);
        }

        return Inertia::render('Kasir/Index', [
            'products' => $products,
            // Harga produk sudah tax-inclusive; klien pakai ini cuma untuk
            // perkiraan tampilan keranjang — server selalu hitung ulang saat
            // checkout (lihat SaleService).
            'ppnActive' => $setting->ppn_active,
            'productDisplayMode' => $setting->product_display_mode,
            'productStock' => $productStock,
            'showStockOnButton' => $setting->show_stock_on_button,
            'showProductImage' => $setting->show_product_image,
            'paymentQuickAmounts' => $setting->payment_quick_amounts,
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:cash'],
            // Wajib sekarang -- form kasir web selalu punya field "Uang
            // Diterima" (lihat Kasir/Index.jsx), sama seperti mobile.
            // SaleService::createSale() memvalidasi ulang (cash_received
            // >= grand_total, change_amount = cash_received − grand_total)
            // memakai grand_total SERVER, tidak pernah percaya hitungan
            // klien begitu saja.
            'cash_received' => ['required', 'numeric', 'min:0'],
            'change_amount' => ['required', 'numeric', 'min:0'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.product_name' => ['nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        // Satu outlet untuk sekarang (lihat docs/ROADMAP.md) — begitu multi-outlet
        // dibutuhkan, outlet aktif harus datang dari sesi/pilihan kasir, bukan first().
        $outlet = Outlet::firstOrFail();
        $warehouse = Warehouse::where('outlet_id', $outlet->id)->firstOrFail();

        try {
            $sale = $this->sales->createSale([
                'outlet_id' => $outlet->id,
                'warehouse_id' => $warehouse->id,
                'created_by_user_id' => $request->user()->id,
                'date' => now(),
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'cash_received' => $validated['cash_received'],
                'change_amount' => $validated['change_amount'],
                'cash_account_code' => $validated['cash_account_code'] ?? CashAccountService::DEFAULT_CODE,
                'lines' => $validated['lines'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return Redirect::route('kasir.index')->with('error', 'Transaksi gagal: '.$e->getMessage());
        }

        $total = number_format((float) $sale->grand_total, 0, ',', '.');

        return Redirect::route('kasir.index')
            ->with('success', "Transaksi #{$sale->id} berhasil. Total: Rp{$total}.")
            ->with('sale_id', $sale->id);
    }
}
