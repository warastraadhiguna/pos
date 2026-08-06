<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\CompanySettingLog;
use App\Services\CashAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Halaman "Pengaturan" tunggal untuk seluruh setting toko (bukan cuma
 * pajak) -- setiap setting baru ke depan cukup tambah bagian di halaman
 * yang sama (`Settings/Index.jsx`), bukan halaman terpisah.
 */
class SettingController extends Controller
{
    public function __construct(private readonly CashAccountService $cashAccounts) {}

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
            'mobilePrintReceipt' => $setting->mobile_print_receipt,
            'memberEnabled' => $setting->member_enabled,
            'tableEnabled' => $setting->table_enabled,
            'noteEnabled' => $setting->note_enabled,
            'variationEnabled' => $setting->variation_enabled,
            'draftEnabled' => $setting->draft_enabled,
            'qrisEnabled' => $setting->qris_enabled,
            'qrisCashAccountCode' => $setting->qris_cash_account_code,
            // Bank-saja (tanpa Kas) -- QRIS TIDAK PERNAH boleh mengarah ke
            // Kas, lihat CashAccountService::selectableBankAccounts()/
            // InvalidQrisAccountException. Selalu dimuat (murah), pola sama
            // `tables`/`noteTemplates` di Kasir\SaleController -- daftar
            // sudah siap begitu admin baru menambah akun Bank pertamanya,
            // tanpa reload halaman.
            'bankAccounts' => $this->cashAccounts->selectableBankAccounts(),
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

    /**
     * Saklar "cetak struk otomatis di HP kasir" -- murni preferensi
     * operasional (bawa/tidak bawa printer), sengaja TIDAK dicatat ke
     * company_setting_logs, pola sama dengan setting tampilan lainnya.
     */
    public function updateMobilePrintReceipt(Request $request): RedirectResponse
    {
        $data = $request->validate(['mobile_print_receipt' => ['required', 'boolean']]);

        CompanySetting::current()->update(['mobile_print_receipt' => $data['mobile_print_receipt']]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['mobile_print_receipt']
                ? 'Cetak struk otomatis di HP kasir diaktifkan.'
                : 'Cetak struk otomatis di HP kasir dimatikan — checkout tidak akan mencoba mencetak.',
        );
    }

    /**
     * Saklar global fitur Member/Pelanggan. OFF berarti field pelanggan
     * tidak muncul di kasir manapun (web/mobile) maupun di struk, DAN data
     * member tidak ikut disinkronkan ke mobile sama sekali (lihat
     * Api\ProductController meta.member_enabled + Api\MemberController) --
     * bukan cuma disembunyikan tampilannya. Sengaja TIDAK dicatat ke
     * company_setting_logs, sama seperti mobile_print_receipt di atas.
     */
    public function updateMemberEnabled(Request $request): RedirectResponse
    {
        $data = $request->validate(['member_enabled' => ['required', 'boolean']]);

        CompanySetting::current()->update(['member_enabled' => $data['member_enabled']]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['member_enabled']
                ? 'Fitur Member/Pelanggan diaktifkan.'
                : 'Fitur Member/Pelanggan dimatikan — tidak akan muncul di kasir maupun struk.',
        );
    }

    /**
     * Saklar global fitur Nomor Meja. OFF berarti field meja tidak muncul
     * di kasir manapun (web/mobile) maupun di struk, DAN data meja tidak
     * ikut disinkronkan ke mobile sama sekali (lihat Api\ProductController
     * meta.table_enabled + Api\TableController) -- bukan cuma disembunyikan
     * tampilannya. Sengaja TIDAK dicatat ke company_setting_logs, sama
     * seperti member_enabled/mobile_print_receipt di atas.
     */
    public function updateTableEnabled(Request $request): RedirectResponse
    {
        $data = $request->validate(['table_enabled' => ['required', 'boolean']]);

        CompanySetting::current()->update(['table_enabled' => $data['table_enabled']]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['table_enabled']
                ? 'Fitur Nomor Meja diaktifkan.'
                : 'Fitur Nomor Meja dimatikan — tidak akan muncul di kasir maupun struk.',
        );
    }

    /**
     * Saklar global fitur Catatan + Template. OFF berarti field catatan
     * (per-transaksi maupun per-item) tidak muncul di kasir manapun
     * (web/mobile) maupun di struk, DAN template catatan tidak ikut
     * disinkronkan ke mobile sama sekali (lihat Api\ProductController
     * meta.note_enabled + Api\NoteTemplateController) -- bukan cuma
     * disembunyikan tampilannya. Sengaja TIDAK dicatat ke
     * company_setting_logs, sama seperti member_enabled/table_enabled di atas.
     */
    public function updateNoteEnabled(Request $request): RedirectResponse
    {
        $data = $request->validate(['note_enabled' => ['required', 'boolean']]);

        CompanySetting::current()->update(['note_enabled' => $data['note_enabled']]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['note_enabled']
                ? 'Fitur Catatan diaktifkan.'
                : 'Fitur Catatan dimatikan — tidak akan muncul di kasir maupun struk.',
        );
    }

    /**
     * Saklar global fitur Variasi Berbayar (Tahap 1, harga-saja). OFF
     * berarti pemilih variasi tidak muncul di kasir manapun (web/mobile).
     * BEDA dari member_enabled/table_enabled/note_enabled: data variasi
     * itu sendiri TETAP ikut disinkronkan ke mobile terlepas dari saklar
     * ini (lihat Api\ProductController meta.variation_enabled) -- variasi
     * menempel ke produk seperti BOM, bukan data terpisah yang perlu
     * digating. Sengaja TIDAK dicatat ke company_setting_logs, sama
     * seperti member_enabled/table_enabled/note_enabled di atas.
     */
    public function updateVariationEnabled(Request $request): RedirectResponse
    {
        $data = $request->validate(['variation_enabled' => ['required', 'boolean']]);

        CompanySetting::current()->update(['variation_enabled' => $data['variation_enabled']]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['variation_enabled']
                ? 'Fitur Variasi Berbayar diaktifkan.'
                : 'Fitur Variasi Berbayar dimatikan — tidak akan muncul di kasir.',
        );
    }

    /**
     * Saklar global fitur Draft (nota belum final). OFF berarti kasir
     * mobile tidak melihat tombol "Simpan Draft"/daftar draft sama sekali,
     * checkout selalu langsung seperti sebelum fitur ini ada. BEDA dari
     * SEMUA toggle lain di file ini: draft murni fitur LOKAL di SQLite HP
     * -- server tidak pernah menyimpan/memproses draft apa pun, kolom ini
     * cuma dibaca lewat meta GET /products (lihat Api\ProductController)
     * supaya HP tahu status saklarnya. Sengaja TIDAK dicatat ke
     * company_setting_logs, sama seperti toggle lain di atas.
     */
    public function updateDraftEnabled(Request $request): RedirectResponse
    {
        $data = $request->validate(['draft_enabled' => ['required', 'boolean']]);

        CompanySetting::current()->update(['draft_enabled' => $data['draft_enabled']]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['draft_enabled']
                ? 'Fitur Draft diaktifkan.'
                : 'Fitur Draft dimatikan — tidak akan muncul di kasir.',
        );
    }

    /**
     * Saklar global metode pembayaran QRIS (Tafsir A -- pencatatan, BUKAN
     * integrasi payment gateway; lihat rancangan fitur QRIS) + akun Bank
     * TUJUANNYA, dikirim SEKALIGUS dalam satu submit (pola sama
     * updateKasirDisplay() di atas) -- keduanya saling bergantung, tidak
     * masuk akal mengaktifkan QRIS tanpa akun tujuan atau sebaliknya.
     *
     * qris_cash_account_code WAJIB diisi saat qris_enabled diaktifkan, dan
     * validasi custom di bawah menolaknya kalau kode akun itu bukan akun
     * Bank aktif (via CashAccountService::assertValidCashAccount()) ATAU
     * ternyata Kas sendiri (CashAccountService::DEFAULT_CODE) -- inti fitur
     * QRIS adalah uangnya SELALU mendarat di rekening bank, tidak pernah
     * fisik di laci tunai (lihat InvalidQrisAccountException, yang menolak
     * hal yang sama lagi di SaleService::createSale() sebagai jaring
     * pengaman kedua saat transaksi sungguhan dibuat). Sengaja TIDAK
     * dicatat ke company_setting_logs, pola sama toggle fitur lain di file
     * ini (member/table/note/variation/draft) -- beda dari PPN yang
     * berstatus hukum.
     */
    public function updateQris(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'qris_enabled' => ['required', 'boolean'],
            'qris_cash_account_code' => [
                $request->boolean('qris_enabled') ? 'required' : 'nullable',
                'nullable',
                'string',
                'max:20',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null) {
                        return;
                    }
                    if ($value === CashAccountService::DEFAULT_CODE) {
                        $fail('Akun tujuan QRIS tidak boleh akun Kas -- pilih akun Bank.');

                        return;
                    }
                    try {
                        $this->cashAccounts->assertValidCashAccount($value);
                    } catch (InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        CompanySetting::current()->update([
            'qris_enabled' => $data['qris_enabled'],
            'qris_cash_account_code' => $data['qris_cash_account_code'] ?? null,
        ]);

        return Redirect::route('pengaturan.index')->with(
            'success',
            $data['qris_enabled']
                ? 'Metode pembayaran QRIS diaktifkan.'
                : 'Metode pembayaran QRIS dimatikan — tidak akan muncul di kasir.',
        );
    }
}
