<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\CompanySettingLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Pengaturan" tunggal untuk seluruh setting toko (bukan cuma
 * pajak) -- setiap setting baru ke depan cukup tambah bagian di halaman
 * yang sama (`Settings/Index.jsx`), bukan halaman terpisah.
 */
class SettingController extends Controller
{
    public function index(): Response
    {
        $setting = CompanySetting::current();

        $logs = CompanySettingLog::with('changedBy')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (CompanySettingLog $log) => [
                'ppn_active' => $log->ppn_active,
                'changed_by' => $log->changedBy?->name ?? 'Pengguna terhapus',
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('Settings/Index', [
            'ppnActive' => $setting->ppn_active,
            'productDisplayMode' => $setting->product_display_mode,
            'storeName' => $setting->store_name,
            'storeAddress' => $setting->store_address,
            'storePhone' => $setting->store_phone,
            'receiptFooter' => $setting->receipt_footer,
            'showStockOnButton' => $setting->show_stock_on_button,
            'showProductImage' => $setting->show_product_image,
            'paymentQuickAmounts' => $setting->payment_quick_amounts,
            'logs' => $logs,
        ]);
    }

    /**
     * Mengubah saklar PPN global — HANYA memengaruhi transaksi yang dibuat
     * SETELAH ini (SaleService::createSale() membaca CompanySetting::current()
     * saat itu juga, transaksi lama tidak pernah dihitung ulang). Setiap
     * perubahan NILAI (bukan tiap submit) dicatat ke company_setting_logs.
     */
    public function updatePpn(Request $request): RedirectResponse
    {
        $data = $request->validate(['ppn_active' => ['required', 'boolean']]);

        $setting = CompanySetting::current();

        if ($setting->ppn_active === $data['ppn_active']) {
            return Redirect::route('pengaturan.index')->with(
                'success',
                'Tidak ada perubahan — status PPN memang sudah begitu.',
            );
        }

        $setting->update(['ppn_active' => $data['ppn_active']]);

        CompanySettingLog::create([
            'ppn_active' => $data['ppn_active'],
            'changed_by_user_id' => $request->user()->id,
        ]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['ppn_active']
                ? 'PPN diaktifkan. Berlaku untuk transaksi berikutnya — transaksi lama tidak berubah.'
                : 'PPN dinonaktifkan. Berlaku untuk transaksi berikutnya — transaksi lama tidak berubah.',
        );
    }

    /**
     * Mode tampilan grid produk di kasir (web & mobile) — murni preferensi
     * tampilan, BUKAN keputusan berstatus hukum seperti PPN, jadi sengaja
     * TIDAK dicatat ke company_setting_logs.
     */
    public function updateProductDisplayMode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_display_mode' => ['required', 'in:all,search_only'],
        ]);

        CompanySetting::current()->update(['product_display_mode' => $data['product_display_mode']]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['product_display_mode'] === 'search_only'
                ? 'Mode tampilan produk diubah ke Terbatas — grid kasir kosong sampai kasir mengetik pencarian.'
                : 'Mode tampilan produk diubah ke Semua — grid kasir menampilkan seluruh produk.',
        );
    }

    /**
     * show_stock_on_button/show_product_image -- murni preferensi tampilan
     * kasir (web & mobile), sengaja TIDAK dicatat ke company_setting_logs,
     * pola sama dengan updateProductDisplayMode().
     */
    public function updateKasirDisplay(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'show_stock_on_button' => ['required', 'boolean'],
            'show_product_image' => ['required', 'boolean'],
        ]);

        CompanySetting::current()->update([
            'show_stock_on_button' => $data['show_stock_on_button'],
            'show_product_image' => $data['show_product_image'],
        ]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            'Tampilan tombol produk di kasir diperbarui.',
        );
    }

    /**
     * Nama/alamat/telepon toko -- dicetak di header struk (web & mobile).
     * Semua nullable (toko boleh belum mengisi) -- murni identitas, sengaja
     * TIDAK dicatat ke company_setting_logs.
     */
    public function updateStoreIdentity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['nullable', 'string', 'max:255'],
            'store_address' => ['nullable', 'string', 'max:255'],
            'store_phone' => ['nullable', 'string', 'max:255'],
        ]);

        CompanySetting::current()->update([
            'store_name' => $data['store_name'] ?? null,
            'store_address' => $data['store_address'] ?? null,
            'store_phone' => $data['store_phone'] ?? null,
        ]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            'Identitas toko diperbarui.',
        );
    }

    /**
     * Baris footer di bagian bawah struk (web & mobile). Nullable -- kalau
     * dikosongkan, sisi tampilan (bukan skema) yang memutuskan fallback-nya.
     */
    public function updateReceiptFooter(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'receipt_footer' => ['nullable', 'string', 'max:255'],
        ]);

        CompanySetting::current()->update(['receipt_footer' => $data['receipt_footer'] ?? null]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            'Footer struk diperbarui.',
        );
    }

    /**
     * Daftar nominal tombol cepat "Uang Diterima" di kasir (web & mobile)
     * -- murni preferensi kasir, sengaja TIDAK dicatat ke
     * company_setting_logs, pola sama dengan setting tampilan lainnya.
     * Disimpan terurut (bukan urutan input admin) supaya tombolnya selalu
     * tampil kecil-ke-besar di semua klien tanpa masing-masing harus
     * mengurutkan ulang sendiri.
     */
    public function updatePaymentQuickAmounts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'payment_quick_amounts' => [
                'required',
                'array',
                'min:1',
                'max:'.CompanySetting::MAX_PAYMENT_QUICK_AMOUNTS,
            ],
            'payment_quick_amounts.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $sorted = collect($data['payment_quick_amounts'])->sort()->values()->all();

        CompanySetting::current()->update(['payment_quick_amounts' => $sorted]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            'Nominal pembayaran cepat diperbarui.',
        );
    }
}
