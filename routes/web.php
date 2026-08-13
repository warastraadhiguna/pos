<?php

use App\Http\Controllers\Aset\DepreciationController;
use App\Http\Controllers\Aset\FixedAssetController;
use App\Http\Controllers\Aset\FixedAssetPaymentController;
use App\Http\Controllers\Beban\ExpenseAccountController;
use App\Http\Controllers\Beban\ExpenseController;
use App\Http\Controllers\Beban\ExpensePaymentController;
use App\Http\Controllers\Coa\ChartOfAccountsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\Distribusi\StockDistributionController;
use App\Http\Controllers\KasBank\CashAccountController;
use App\Http\Controllers\KasBank\CashTransferController;
use App\Http\Controllers\Modal\EquityTransactionController;
use App\Http\Controllers\Kasir\SaleController as KasirSaleController;
use App\Http\Controllers\Kasir\SaleHistoryController;
use App\Http\Controllers\Master\ItemCategoryController;
use App\Http\Controllers\Master\ItemController;
use App\Http\Controllers\Master\MemberController;
use App\Http\Controllers\Master\NoteTemplateController;
use App\Http\Controllers\Master\OutletController;
use App\Http\Controllers\Master\ProductCategoryController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\SupplierController;
use App\Http\Controllers\Master\TableController;
use App\Http\Controllers\Master\UomController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\Pembelian\GoodsReceiptController;
use App\Http\Controllers\Pembelian\PurchaseOrderController;
use App\Http\Controllers\Pembelian\SupplierPaymentController;
use App\Http\Controllers\ProductProfitReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\SupplierPayableReportController;
use App\Http\Controllers\TaxReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified', 'permission:kasir.access'])->group(function () {
    Route::get('/kasir', [KasirSaleController::class, 'index'])->name('kasir.index');
    Route::post('/kasir', [KasirSaleController::class, 'store'])->name('kasir.store');
});

Route::middleware(['auth', 'verified', 'permission:penjualan.view'])->prefix('penjualan')->name('penjualan.')->group(function () {
    Route::get('/', [SaleHistoryController::class, 'index'])->name('index');
    Route::get('/{sale}', [SaleHistoryController::class, 'show'])->name('show');
    Route::get('/{sale}/struk', [SaleHistoryController::class, 'receipt'])->name('receipt');
});

Route::middleware(['auth', 'verified', 'permission:pengguna.manage'])->prefix('pengguna')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::get('/create', [UserController::class, 'create'])->name('create');
    Route::post('/', [UserController::class, 'store'])->name('store');
    Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
    Route::put('/{user}', [UserController::class, 'update'])->name('update');
    Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
});

Route::middleware(['auth', 'verified', 'permission:roles.manage'])->prefix('roles')->name('roles.')->group(function () {
    Route::get('/', [RoleController::class, 'index'])->name('index');
    Route::get('/create', [RoleController::class, 'create'])->name('create');
    Route::post('/', [RoleController::class, 'store'])->name('store');
    Route::get('/{role}/edit', [RoleController::class, 'edit'])->name('edit');
    Route::put('/{role}', [RoleController::class, 'update'])->name('update');
    Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
});

Route::middleware(['auth', 'verified', 'permission:company-settings.manage'])->prefix('pengaturan')->name('pengaturan.')->group(function () {
    Route::get('/', [SettingController::class, 'index'])->name('index');
    Route::put('/ppn', [SettingController::class, 'updatePpn'])->name('ppn.update');
    Route::put('/tampilan-produk', [SettingController::class, 'updateProductDisplayMode'])->name('tampilan-produk.update');
    Route::put('/tampilan-kasir', [SettingController::class, 'updateKasirDisplay'])->name('tampilan-kasir.update');
    Route::put('/identitas-toko', [SettingController::class, 'updateStoreIdentity'])->name('identitas-toko.update');
    Route::put('/struk', [SettingController::class, 'updateReceiptFooter'])->name('struk.update');
    Route::put('/nominal-bayar', [SettingController::class, 'updatePaymentQuickAmounts'])->name('nominal-bayar.update');
    Route::put('/cetak-struk-mobile', [SettingController::class, 'updateMobilePrintReceipt'])->name('cetak-struk-mobile.update');
    Route::put('/member', [SettingController::class, 'updateMemberEnabled'])->name('member.update');
    Route::put('/qris', [SettingController::class, 'updateQris'])->name('qris.update');
});

// Role Developer (hidden super-admin) -- toggle ON/OFF fitur (Multi-Cabang,
// grace period Device Binding, Meja, Catatan, Variasi, Draft) adalah
// pengaturan KERANGKA sistem -- developer yang MENGONFIGURASI kapabilitas
// apa yang tersedia, admin yang MENJALANKAN bisnis dengan fitur yang
// diberikan (lihat rancangan yang disetujui). Digerbangi permission
// TERPISAH dari `company-settings.manage` di atas walau berbagi prefix
// URL/halaman yang sama (pola sama `devices.manage` di bawah, prefix
// `pengaturan/*` beda grup middleware). PENTING -- ini CUMA saklar ON/OFF:
// kelola ISI fitur (Kelola Meja/Template Catatan/Produk & Variasi) TETAP
// `master-data.manage` (admin), route TERPISAH SAMA SEKALI (lihat grup
// `master.*` di bawah) -- admin tetap penuh mengelola data, developer cuma
// yang bisa mematikan/menyalakan fiturnya. Admin existing kehilangan akses
// ke 4 toggle baru ini (persis seperti Multi-Cabang/grace period
// sebelumnya) -- TAPI TIDAK butuh migrasi transisi baru: `system.manage`
// sendiri (siapa yang boleh memegangnya) sudah settled sejak migrasi
// `..._400200_.../..._400300_...`, cuma route mana yang digerbangi
// permission itu yang bertambah -- akun Developer yang sudah ada otomatis
// bisa akses 4 toggle ini juga lewat bypass (lihat User::hasPermission()),
// tidak ada jendela "belum ada yang bisa akses" yang baru terbuka.
Route::middleware(['auth', 'verified', 'permission:system.manage'])->prefix('pengaturan')->name('pengaturan.')->group(function () {
    Route::put('/device-binding-grace-period', [SettingController::class, 'updateDeviceBindingGracePeriod'])->name('device-binding-grace-period.update');
    Route::put('/multi-cabang', [SettingController::class, 'updateMultiBranchEnabled'])->name('multi-branch.update');
    Route::put('/meja', [SettingController::class, 'updateTableEnabled'])->name('meja.update');
    Route::put('/catatan', [SettingController::class, 'updateNoteEnabled'])->name('catatan.update');
    Route::put('/variasi', [SettingController::class, 'updateVariationEnabled'])->name('variasi.update');
    Route::put('/draft', [SettingController::class, 'updateDraftEnabled'])->name('draft.update');
});

// Device Binding -- halaman TERSEMBUNYI (URL disengaja tidak dicantumkan di
// navGroups AuthenticatedLayout.jsx, lihat DeviceController), tapi tetap
// digerbangi permission seperti halaman admin lain manapun -- "tersembunyi"
// murni UX, otorisasi tetap penuh lewat middleware ini.
Route::middleware(['auth', 'verified', 'permission:devices.manage'])->prefix('pengaturan/perangkat')->name('devices.')->group(function () {
    Route::get('/', [DeviceController::class, 'index'])->name('index');
    Route::put('/{device}/approve', [DeviceController::class, 'approve'])->name('approve');
    Route::put('/{device}/revoke', [DeviceController::class, 'revoke'])->name('revoke');
    Route::put('/{device}/outlet', [DeviceController::class, 'assignOutlet'])->name('assign-outlet');
});

// Multi-Cabang Lapisan 1 -- "Kelola Cabang". Permission TERPISAH dari
// master-data.manage (bukan digabung ke grup di bawah) karena struktur
// cabang menyentuh skoping akuntansi/kas (poin 5 rancangan), sensitivitas
// setara coa.manage/modal.manage/devices.manage -- BEDA dari halaman
// Device Binding: route ini ADA di navigasi (AuthenticatedLayout.jsx,
// feature-gated `multi_branch_enabled`), tidak disembunyikan.
Route::middleware(['auth', 'verified', 'permission:branches.manage'])->prefix('master/outlets')->name('master.outlets.')->group(function () {
    Route::get('/', [OutletController::class, 'index'])->name('index');
    Route::get('/create', [OutletController::class, 'create'])->name('create');
    Route::post('/', [OutletController::class, 'store'])->name('store');
    Route::get('/{outlet}/edit', [OutletController::class, 'edit'])->name('edit');
    Route::put('/{outlet}', [OutletController::class, 'update'])->name('update');
    Route::delete('/{outlet}', [OutletController::class, 'destroy'])->name('destroy');
});

Route::middleware(['auth', 'verified', 'permission:master-data.manage'])->prefix('master')->name('master.')->group(function () {
    Route::resource('uoms', UomController::class)->except(['show']);
    Route::get('suppliers/search', [SupplierController::class, 'search'])->name('suppliers.search');
    Route::resource('suppliers', SupplierController::class)->except(['show']);
    Route::post('items/quick-create', [ItemController::class, 'quickCreate'])->name('items.quick-create');
    Route::get('items/search', [ItemController::class, 'search'])->name('items.search');
    Route::resource('items', ItemController::class)->except(['show']);
    Route::resource('products', ProductController::class)->except(['show']);
    Route::resource('product-categories', ProductCategoryController::class)->except(['show']);
    Route::resource('item-categories', ItemCategoryController::class)->except(['show']);
    Route::get('members/search', [MemberController::class, 'search'])->name('members.search');
    Route::resource('members', MemberController::class)->except(['show']);
    Route::resource('tables', TableController::class)->except(['show']);
    Route::resource('note-templates', NoteTemplateController::class)->except(['show']);
});

Route::middleware(['auth', 'verified', 'permission:pembelian.manage'])->prefix('pembelian')->name('pembelian.')->group(function () {
    Route::resource('purchase-orders', PurchaseOrderController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('purchase-orders/{purchaseOrder}/receive', [GoodsReceiptController::class, 'create'])->name('purchase-orders.receive.create');
    Route::post('purchase-orders/{purchaseOrder}/receive', [GoodsReceiptController::class, 'store'])->name('purchase-orders.receive.store');
    Route::get('supplier-payments/summary', [SupplierPaymentController::class, 'summary'])->name('supplier-payments.summary');
    Route::get('supplier-payments/fifo-preview', [SupplierPaymentController::class, 'fifoPreview'])->name('supplier-payments.fifo-preview');
    Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->name('supplier-payments.index');
    Route::get('supplier-payments/create', [SupplierPaymentController::class, 'create'])->name('supplier-payments.create');
    Route::post('supplier-payments', [SupplierPaymentController::class, 'store'])->name('supplier-payments.store');
});

Route::middleware(['auth', 'verified', 'permission:beban.manage'])->prefix('beban')->name('beban.')->group(function () {
    Route::get('/', [ExpenseController::class, 'index'])->name('index');
    Route::get('/create', [ExpenseController::class, 'create'])->name('create');
    Route::post('/', [ExpenseController::class, 'store'])->name('store');
    Route::get('/pelunasan', [ExpensePaymentController::class, 'index'])->name('payments.index');
    Route::post('/pelunasan', [ExpensePaymentController::class, 'store'])->name('payments.store');
    Route::get('/akun', [ExpenseAccountController::class, 'index'])->name('accounts.index');
    Route::post('/akun', [ExpenseAccountController::class, 'store'])->name('accounts.store');
    Route::put('/akun/{account}/toggle-active', [ExpenseAccountController::class, 'toggleActive'])->name('accounts.toggle-active');
});

Route::middleware(['auth', 'verified', 'permission:kas-bank.manage'])->prefix('kas-bank')->name('kas-bank.')->group(function () {
    Route::get('/transfer', [CashTransferController::class, 'index'])->name('transfers.index');
    Route::get('/transfer/create', [CashTransferController::class, 'create'])->name('transfers.create');
    Route::post('/transfer', [CashTransferController::class, 'store'])->name('transfers.store');
    Route::get('/akun', [CashAccountController::class, 'index'])->name('accounts.index');
    Route::post('/akun', [CashAccountController::class, 'store'])->name('accounts.store');
    Route::put('/akun/{account}/toggle-active', [CashAccountController::class, 'toggleActive'])->name('accounts.toggle-active');
});

Route::middleware(['auth', 'verified', 'permission:modal.manage'])->prefix('modal')->name('modal.')->group(function () {
    Route::get('/', [EquityTransactionController::class, 'index'])->name('index');
    Route::get('/setor/create', [EquityTransactionController::class, 'createDeposit'])->name('deposit.create');
    Route::post('/setor', [EquityTransactionController::class, 'storeDeposit'])->name('deposit.store');
    Route::get('/prive/create', [EquityTransactionController::class, 'createWithdrawal'])->name('withdrawal.create');
    Route::post('/prive', [EquityTransactionController::class, 'storeWithdrawal'])->name('withdrawal.store');
});

Route::middleware(['auth', 'verified', 'permission:aset.manage'])->prefix('aset')->name('aset.')->group(function () {
    Route::get('/', [FixedAssetController::class, 'index'])->name('index');
    Route::get('/create', [FixedAssetController::class, 'create'])->name('create');
    Route::post('/', [FixedAssetController::class, 'store'])->name('store');
    Route::get('/penyusutan', [DepreciationController::class, 'index'])->name('depreciation.index');
    Route::post('/penyusutan', [DepreciationController::class, 'process'])->name('depreciation.process');
    Route::get('/pelunasan', [FixedAssetPaymentController::class, 'index'])->name('payments.index');
    Route::post('/pelunasan', [FixedAssetPaymentController::class, 'store'])->name('payments.store');
});

Route::middleware(['auth', 'verified', 'permission:coa.manage'])->prefix('coa')->name('coa.')->group(function () {
    Route::get('/', [ChartOfAccountsController::class, 'index'])->name('index');
    Route::post('/', [ChartOfAccountsController::class, 'store'])->name('store');
    Route::put('/{account}/toggle-active', [ChartOfAccountsController::class, 'toggleActive'])->name('toggle-active');
});

Route::middleware(['auth', 'verified', 'permission:stock-opname.manage'])->prefix('stock-opname')->name('stock-opname.')->group(function () {
    Route::get('/', [StockOpnameController::class, 'index'])->name('index');
    Route::get('/create', [StockOpnameController::class, 'create'])->name('create');
    Route::post('/', [StockOpnameController::class, 'store'])->name('store');
    Route::get('/{stockOpname}', [StockOpnameController::class, 'show'])->name('show');
    Route::post('/{stockOpname}/post', [StockOpnameController::class, 'post'])->name('post');
});

// Multi-Cabang Lapisan 2 -- "Distribusi Stok" (pusat->cabang). Dokumen
// (index/create/store/show) TANPA edit/update/destroy -- immutable begitu
// dibuat, sama seperti purchase_orders. `execute`/`cancel` adalah aksi
// terpisah (pola sama purchase-orders.receive.*), bukan bagian resource.
Route::middleware(['auth', 'verified', 'permission:distributions.manage'])->prefix('distribusi')->name('distribusi.')->group(function () {
    Route::resource('stock-distributions', StockDistributionController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('stock-distributions/{stockDistribution}/execute', [StockDistributionController::class, 'execute'])->name('stock-distributions.execute');
    Route::post('stock-distributions/{stockDistribution}/cancel', [StockDistributionController::class, 'cancel'])->name('stock-distributions.cancel');
});

Route::middleware(['auth', 'verified', 'permission:laporan.view'])->prefix('laporan')->name('laporan.')->group(function () {
    Route::get('/neraca', [FinancialReportController::class, 'balanceSheet'])->name('neraca');
    Route::get('/laba-rugi', [FinancialReportController::class, 'incomeStatement'])->name('laba-rugi');
    Route::get('/beban', [FinancialReportController::class, 'expenseReport'])->name('beban');
    Route::get('/ppn', [TaxReportController::class, 'ppn'])->name('ppn');
    Route::get('/penjualan', [SalesReportController::class, 'index'])->name('penjualan');
    Route::get('/perbandingan-cabang', [SalesReportController::class, 'compare'])->name('perbandingan-cabang');
    Route::get('/laba-produk', [ProductProfitReportController::class, 'index'])->name('laba-produk');
    Route::get('/stok', [InventoryReportController::class, 'index'])->name('stok');
    Route::get('/hutang', [SupplierPayableReportController::class, 'index'])->name('hutang');
    Route::get('/hutang/{supplier}', [SupplierPayableReportController::class, 'show'])->name('hutang.show');
});

require __DIR__.'/auth.php';
