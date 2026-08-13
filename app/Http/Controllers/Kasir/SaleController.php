<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\DiningTable;
use App\Models\NoteTemplate;
use App\Models\Product;
use App\Services\BranchService;
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
        private readonly BranchService $branches,
    ) {}

    public function index(Request $request): Response
    {
        $setting = CompanySetting::current();

        // Komponen BOM cuma perlu dimuat kalau stok memang ditampilkan --
        // query producibleQtyForProducts() tetap murah (satu batch query),
        // tapi tidak ada gunanya kalau togglenya mati. Variasi (Tahap 1)
        // SELALU dimuat sama seperti taxRate -- ringan (satu-dua baris per
        // produk) dan dibutuhkan tombol produk untuk tahu apakah harus
        // membuka pemilih variasi saat di-tap, terlepas dari
        // variation_enabled (yang menggerbangi TAMPILANnya di Kasir/Index.jsx).
        $products = $setting->show_stock_on_button
            ? Product::with(['taxRate', 'components.item', 'components.uom', 'variations'])->where('is_active', true)->orderBy('name')->get()
            : Product::with(['taxRate', 'variations'])->where('is_active', true)->orderBy('name')->get();

        $productStock = null;
        if ($setting->show_stock_on_button) {
            // Multi-Cabang Lapisan 3 -- stok yang ditampilkan di tombol
            // produk sekarang mencerminkan gudang CABANG kasir ini, bukan
            // selalu pusat (lihat BranchService::resolveCurrentOutlet()).
            $outlet = $this->branches->resolveCurrentOutlet($request->user());
            $warehouse = $outlet->warehouses()->firstOrFail();
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
            // QRIS (Tafsir A -- pencatatan, lihat rancangan fitur QRIS):
            // qrisEnabled menggerbangi apakah toggle Tunai/QRIS muncul sama
            // sekali di kasir; bankAccounts (Bank-saja, tanpa Kas) mengisi
            // pemilih "Masuk Ke" begitu QRIS dipilih -- uang QRIS TIDAK
            // PERNAH boleh mengarah ke Kas, lihat SaleService::createSale().
            'qrisEnabled' => $setting->qris_enabled,
            'qrisCashAccountCode' => $setting->qris_cash_account_code,
            'bankAccounts' => $this->cashAccounts->selectableBankAccounts(),
            'memberEnabled' => $setting->member_enabled,
            'tableEnabled' => $setting->table_enabled,
            // Meja dipilih dari daftar SAJA (bukan ketik bebas seperti
            // Member) -- jumlah meja tetap/terbatas, jadi dropdown penuh
            // lebih aman daripada risiko salah ketik. Selalu dimuat (murah,
            // satu query kecil) sama seperti cashAccounts di atas, bukan
            // cuma saat tableEnabled aktif -- kalau admin baru menyalakan
            // togglenya, daftar sudah siap tanpa reload halaman.
            'tables' => DiningTable::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'noteEnabled' => $setting->note_enabled,
            // Sama alasan `tables` di atas -- selalu dimuat (murah), bukan
            // cuma saat noteEnabled aktif, supaya siap tanpa reload halaman
            // begitu admin baru menyalakan togglenya.
            'noteTemplates' => NoteTemplate::where('is_active', true)->orderBy('text')->get(['id', 'text']),
            'variationEnabled' => $setting->variation_enabled,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // 'qris' (Tafsir A -- pencatatan, lihat rancangan fitur QRIS):
            // form kasir web mengirim cash_received/change_amount = total/
            // 0 juga untuk QRIS (field itu disembunyikan tapi tetap
            // dikirim apa adanya, lihat Kasir/Index.jsx) -- SaleService
            // MENGABAIKANNYA sepenuhnya untuk qris (dipaksa pas di sana),
            // jadi tidak perlu percabangan validasi di sini.
            'payment_method' => ['nullable', 'string', 'in:cash,qris'],
            // Wajib sekarang -- form kasir web selalu punya field "Uang
            // Diterima" (lihat Kasir/Index.jsx), sama seperti mobile.
            // SaleService::createSale() memvalidasi ulang (cash_received
            // >= grand_total, change_amount = cash_received − grand_total)
            // memakai grand_total SERVER, tidak pernah percaya hitungan
            // klien begitu saja.
            'cash_received' => ['required', 'numeric', 'min:0'],
            'change_amount' => ['required', 'numeric', 'min:0'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
            // Keduanya opsional & independen -- lihat docblock
            // SaleService::createSale(). member_id datang dari kasir yang
            // memilih dari MemberCombobox; member_name bisa isi bebas
            // (pelanggan walk-in tanpa dipilih dari daftar) atau dikosongkan
            // sepenuhnya kalau fitur member nonaktif / kasir tidak mengisi apa pun.
            'member_id' => ['nullable', 'exists:members,id'],
            'member_name' => ['nullable', 'string', 'max:255'],
            // Sama pola member_id/member_name di atas -- lihat docblock
            // SaleService::createSale().
            'table_id' => ['nullable', 'exists:tables,id'],
            'table_name' => ['nullable', 'string', 'max:255'],
            // Catatan per-transaksi & per-item -- keduanya teks bebas apa
            // adanya, lihat docblock SaleService::createSale().
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.product_name' => ['nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
            // Variasi Berbayar (Tahap 1, harga-saja) -- lihat docblock
            // SaleService::createSale(). unit_price di atas SUDAH termasuk
            // additional_price tiap variasi terpilih (dihitung klien);
            // array ini murni rincian snapshot untuk nota, tidak memengaruhi
            // perhitungan harga sama sekali.
            'lines.*.variations' => ['array'],
            'lines.*.variations.*.variation_id' => ['required', 'exists:product_variations,id'],
            'lines.*.variations.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.variations.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Multi-Cabang Lapisan 3 -- cabang kasir ini ditentukan dari
        // user.outlet_id (kasir web tidak punya konsep device fisik),
        // dengan pusat sebagai fallback -- lihat
        // BranchService::resolveCurrentOutlet(). multi_branch_enabled=false
        // (default) SELALU pusat, perilaku identik sebelum Lapisan 3 ada.
        $outlet = $this->branches->resolveCurrentOutlet($request->user());
        $warehouse = $outlet->warehouses()->firstOrFail();

        try {
            $sale = $this->sales->createSale([
                'outlet_id' => $outlet->id,
                'warehouse_id' => $warehouse->id,
                'created_by_user_id' => $request->user()->id,
                'date' => now(),
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'cash_received' => $validated['cash_received'],
                'change_amount' => $validated['change_amount'],
                // Kasir yang MEMANG memilih akun tertentu di picker tetap
                // menang apa adanya; kalau tidak, SaleService meresolve Kas
                // CABANG ini sendiri (bukan selalu Kas pusat) -- lihat
                // CashAccountService::resolveCashAccountCodeForOutlet().
                'cash_account_code' => $validated['cash_account_code'] ?? null,
                'member_id' => $validated['member_id'] ?? null,
                'member_name' => $validated['member_name'] ?? null,
                'table_id' => $validated['table_id'] ?? null,
                'table_name' => $validated['table_name'] ?? null,
                'note' => $validated['note'] ?? null,
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
