import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const formatDateTime = (iso) =>
    new Date(iso).toLocaleString('id-ID', {
        dateStyle: 'long',
        timeStyle: 'short',
    });

// Halaman "Pengaturan" tunggal untuk seluruh setting toko -- tiap setting
// baru ke depan cukup tambah SATU bagian berjudul di sini (pola yang sama
// dengan dua bagian di bawah), bukan halaman terpisah lagi.
export default function Index({
    ppnActive,
    productDisplayMode,
    storeName,
    storeAddress,
    storePhone,
    receiptFooter,
    showStockOnButton,
    showProductImage,
    paymentQuickAmounts,
    mobilePrintReceipt,
    memberEnabled,
    tableEnabled,
    noteEnabled,
    variationEnabled,
    draftEnabled,
    qrisEnabled,
    qrisCashAccountCode,
    bankAccounts,
    deviceBindingGracePeriodEndsAt,
    deviceBindingGracePeriodActive,
    multiBranchEnabled,
    logs,
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [displayMode, setDisplayMode] = useState(productDisplayMode);
    const [savingDisplayMode, setSavingDisplayMode] = useState(false);
    const [stockOnButton, setStockOnButton] = useState(showStockOnButton);
    const [productImage, setProductImage] = useState(showProductImage);
    const [savingKasirDisplay, setSavingKasirDisplay] = useState(false);

    const [storeForm, setStoreForm] = useState({
        store_name: storeName ?? '',
        store_address: storeAddress ?? '',
        store_phone: storePhone ?? '',
    });
    const [savingStore, setSavingStore] = useState(false);

    const [footerForm, setFooterForm] = useState(receiptFooter ?? '');
    const [savingFooter, setSavingFooter] = useState(false);

    const [printReceipt, setPrintReceipt] = useState(mobilePrintReceipt);
    const [savingPrintReceipt, setSavingPrintReceipt] = useState(false);

    const [memberOn, setMemberOn] = useState(memberEnabled);
    const [savingMemberEnabled, setSavingMemberEnabled] = useState(false);

    const [tableOn, setTableOn] = useState(tableEnabled);
    const [savingTableEnabled, setSavingTableEnabled] = useState(false);

    const [noteOn, setNoteOn] = useState(noteEnabled);
    const [savingNoteEnabled, setSavingNoteEnabled] = useState(false);

    const [variationOn, setVariationOn] = useState(variationEnabled);
    const [savingVariationEnabled, setSavingVariationEnabled] = useState(false);

    const [draftOn, setDraftOn] = useState(draftEnabled);
    const [savingDraftEnabled, setSavingDraftEnabled] = useState(false);

    const [qrisOn, setQrisOn] = useState(qrisEnabled);
    // '' (bukan null) supaya cocok dengan value <SelectInput> -- default ke
    // akun Bank pertama kalau belum pernah diatur & sudah ada minimal satu
    // akun Bank, sama seperti default cashAccountCode di Kasir/Index.jsx.
    const [qrisAccountCode, setQrisAccountCode] = useState(
        qrisCashAccountCode ?? bankAccounts[0]?.code ?? '',
    );
    const [savingQris, setSavingQris] = useState(false);
    const qrisErrors = usePage().props.errors ?? {};

    const [graceDays, setGraceDays] = useState('14');
    const [savingGracePeriod, setSavingGracePeriod] = useState(false);
    const gracePeriodErrors = usePage().props.errors ?? {};

    const [multiBranchOn, setMultiBranchOn] = useState(multiBranchEnabled);
    const [savingMultiBranch, setSavingMultiBranch] = useState(false);

    // String (bukan number) di state INPUT supaya kolom bisa dikosongkan
    // sementara saat diketik ulang tanpa langsung jadi "0" -- dikonversi
    // ke integer cuma saat submit.
    const [quickAmounts, setQuickAmounts] = useState(
        (paymentQuickAmounts ?? []).map(String),
    );
    const [savingQuickAmounts, setSavingQuickAmounts] = useState(false);
    const quickAmountsErrors = usePage().props.errors ?? {};

    const pendingValue = !ppnActive;

    const submit = () => {
        setProcessing(true);
        router.put(
            route('pengaturan.ppn.update'),
            { ppn_active: pendingValue },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setConfirming(false);
                },
            },
        );
    };

    const submitDisplayMode = (mode) => {
        if (mode === displayMode || savingDisplayMode) return;
        setDisplayMode(mode);
        setSavingDisplayMode(true);
        router.put(
            route('pengaturan.tampilan-produk.update'),
            { product_display_mode: mode },
            {
                preserveScroll: true,
                onError: () => setDisplayMode(productDisplayMode),
                onFinish: () => setSavingDisplayMode(false),
            },
        );
    };

    const submitKasirDisplay = (next) => {
        if (savingKasirDisplay) return;
        const previous = { stockOnButton, productImage };
        setStockOnButton(next.stockOnButton);
        setProductImage(next.productImage);
        setSavingKasirDisplay(true);
        router.put(
            route('pengaturan.tampilan-kasir.update'),
            {
                show_stock_on_button: next.stockOnButton,
                show_product_image: next.productImage,
            },
            {
                preserveScroll: true,
                onError: () => {
                    setStockOnButton(previous.stockOnButton);
                    setProductImage(previous.productImage);
                },
                onFinish: () => setSavingKasirDisplay(false),
            },
        );
    };

    const submitStoreIdentity = (e) => {
        e.preventDefault();
        setSavingStore(true);
        router.put(route('pengaturan.identitas-toko.update'), storeForm, {
            preserveScroll: true,
            onFinish: () => setSavingStore(false),
        });
    };

    const addQuickAmount = () => {
        if (quickAmounts.length >= 8) return;
        setQuickAmounts((prev) => [...prev, '']);
    };

    const removeQuickAmount = (index) => {
        setQuickAmounts((prev) => prev.filter((_, i) => i !== index));
    };

    const updateQuickAmount = (index, value) => {
        setQuickAmounts((prev) =>
            prev.map((amount, i) => (i === index ? value : amount)),
        );
    };

    const submitQuickAmounts = (e) => {
        e.preventDefault();
        setSavingQuickAmounts(true);
        router.put(
            route('pengaturan.nominal-bayar.update'),
            {
                payment_quick_amounts: quickAmounts
                    .map((amount) => Number(amount))
                    .filter((amount) => Number.isFinite(amount) && amount > 0),
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingQuickAmounts(false),
            },
        );
    };

    const submitReceiptFooter = (e) => {
        e.preventDefault();
        setSavingFooter(true);
        router.put(
            route('pengaturan.struk.update'),
            { receipt_footer: footerForm },
            {
                preserveScroll: true,
                onFinish: () => setSavingFooter(false),
            },
        );
    };

    const submitMobilePrintReceipt = (checked) => {
        if (savingPrintReceipt) return;
        const previous = printReceipt;
        setPrintReceipt(checked);
        setSavingPrintReceipt(true);
        router.put(
            route('pengaturan.cetak-struk-mobile.update'),
            { mobile_print_receipt: checked },
            {
                preserveScroll: true,
                onError: () => setPrintReceipt(previous),
                onFinish: () => setSavingPrintReceipt(false),
            },
        );
    };

    const submitMemberEnabled = (checked) => {
        if (savingMemberEnabled) return;
        const previous = memberOn;
        setMemberOn(checked);
        setSavingMemberEnabled(true);
        router.put(
            route('pengaturan.member.update'),
            { member_enabled: checked },
            {
                preserveScroll: true,
                onError: () => setMemberOn(previous),
                onFinish: () => setSavingMemberEnabled(false),
            },
        );
    };

    const submitTableEnabled = (checked) => {
        if (savingTableEnabled) return;
        const previous = tableOn;
        setTableOn(checked);
        setSavingTableEnabled(true);
        router.put(
            route('pengaturan.meja.update'),
            { table_enabled: checked },
            {
                preserveScroll: true,
                onError: () => setTableOn(previous),
                onFinish: () => setSavingTableEnabled(false),
            },
        );
    };

    const submitNoteEnabled = (checked) => {
        if (savingNoteEnabled) return;
        const previous = noteOn;
        setNoteOn(checked);
        setSavingNoteEnabled(true);
        router.put(
            route('pengaturan.catatan.update'),
            { note_enabled: checked },
            {
                preserveScroll: true,
                onError: () => setNoteOn(previous),
                onFinish: () => setSavingNoteEnabled(false),
            },
        );
    };

    const submitVariationEnabled = (checked) => {
        if (savingVariationEnabled) return;
        const previous = variationOn;
        setVariationOn(checked);
        setSavingVariationEnabled(true);
        router.put(
            route('pengaturan.variasi.update'),
            { variation_enabled: checked },
            {
                preserveScroll: true,
                onError: () => setVariationOn(previous),
                onFinish: () => setSavingVariationEnabled(false),
            },
        );
    };

    const submitDraftEnabled = (checked) => {
        if (savingDraftEnabled) return;
        const previous = draftOn;
        setDraftOn(checked);
        setSavingDraftEnabled(true);
        router.put(
            route('pengaturan.draft.update'),
            { draft_enabled: checked },
            {
                preserveScroll: true,
                onError: () => setDraftOn(previous),
                onFinish: () => setSavingDraftEnabled(false),
            },
        );
    };

    // Toggle QRIS DAN akun tujuan dikirim SEKALIGUS (bukan dua submit
    // terpisah seperti toggle lain) -- keduanya saling bergantung, lihat
    // docblock SettingController::updateQris(). Dipanggil baik dari
    // checkbox (toggle) maupun dropdown akun (ganti akun tanpa mengubah
    // status aktif) via [overrides].
    const submitQris = (overrides = {}) => {
        if (savingQris) return;
        const next = { qrisOn, qrisAccountCode, ...overrides };
        const previous = { qrisOn, qrisAccountCode };
        setQrisOn(next.qrisOn);
        setQrisAccountCode(next.qrisAccountCode);
        setSavingQris(true);
        router.put(
            route('pengaturan.qris.update'),
            {
                qris_enabled: next.qrisOn,
                qris_cash_account_code: next.qrisAccountCode || null,
            },
            {
                preserveScroll: true,
                onError: () => {
                    setQrisOn(previous.qrisOn);
                    setQrisAccountCode(previous.qrisAccountCode);
                },
                onFinish: () => setSavingQris(false),
            },
        );
    };

    const submitGracePeriodExtend = (e) => {
        e.preventDefault();
        setSavingGracePeriod(true);
        router.put(
            route('pengaturan.device-binding-grace-period.update'),
            { action: 'extend', days: Number(graceDays) },
            { preserveScroll: true, onFinish: () => setSavingGracePeriod(false) },
        );
    };

    const submitMultiBranchEnabled = (checked) => {
        if (savingMultiBranch) return;
        const previous = multiBranchOn;
        setMultiBranchOn(checked);
        setSavingMultiBranch(true);
        router.put(
            route('pengaturan.multi-branch.update'),
            { multi_branch_enabled: checked },
            {
                preserveScroll: true,
                onError: () => setMultiBranchOn(previous),
                onFinish: () => setSavingMultiBranch(false),
            },
        );
    };

    const submitGracePeriodDisable = () => {
        if (savingGracePeriod) return;
        setSavingGracePeriod(true);
        router.put(
            route('pengaturan.device-binding-grace-period.update'),
            { action: 'disable' },
            { preserveScroll: true, onFinish: () => setSavingGracePeriod(false) },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Pengaturan
                </h2>
            }
        >
            <Head title="Pengaturan" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl space-y-8 sm:px-6 lg:px-8">
                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Pajak (PPN)
                        </h3>
                        <div
                            className={
                                'rounded-lg p-6 shadow-sm ' +
                                (ppnActive
                                    ? 'bg-green-50 ring-1 ring-green-200'
                                    : 'bg-gray-100 ring-1 ring-gray-300')
                            }
                        >
                            <p
                                className={
                                    'text-lg font-semibold ' +
                                    (ppnActive ? 'text-green-800' : 'text-gray-700')
                                }
                            >
                                {ppnActive
                                    ? 'PPN AKTIF — harga dihitung termasuk PPN 11%'
                                    : 'PPN NONAKTIF — tidak memungut PPN'}
                            </p>
                            <p className="mt-1 text-sm text-gray-600">
                                {ppnActive
                                    ? 'Setiap transaksi baru (kasir web & aplikasi mobile) akan mengurai PPN 11% dari harga jual.'
                                    : 'Setiap transaksi baru (kasir web & aplikasi mobile) tidak akan memungut PPN sama sekali.'}
                            </p>

                            <div className="mt-4">
                                <PrimaryButton
                                    onClick={() => setConfirming(true)}
                                >
                                    {ppnActive ? 'Nonaktifkan PPN' : 'Aktifkan PPN'}
                                </PrimaryButton>
                            </div>
                        </div>

                        <div className="mt-4 rounded-lg bg-white p-6 shadow-sm">
                            <h4 className="mb-3 font-semibold text-gray-900">
                                Riwayat Perubahan
                            </h4>
                            {logs.length === 0 ? (
                                <p className="text-sm text-gray-500">
                                    Belum pernah diubah lewat halaman ini.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {logs.map((log, index) => (
                                        <li
                                            key={index}
                                            className="flex items-center justify-between border-b border-gray-100 py-2 text-sm last:border-b-0"
                                        >
                                            <span className="text-gray-700">
                                                {log.ppn_active
                                                    ? 'Diaktifkan'
                                                    : 'Dinonaktifkan'}{' '}
                                                oleh{' '}
                                                <span className="font-medium">
                                                    {log.changed_by}
                                                </span>
                                            </span>
                                            <span className="text-gray-400">
                                                {formatDateTime(log.created_at)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Tampilan Produk
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm text-gray-600">
                                Berlaku untuk kasir web dan aplikasi mobile.
                                Berguna kalau katalog produk sangat banyak —
                                grid kasir tidak perlu memuat semuanya sekaligus.
                            </p>

                            <div className="mt-4 space-y-3">
                                <label className="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 p-3 hover:bg-gray-50">
                                    <input
                                        type="radio"
                                        name="product_display_mode"
                                        className="mt-1 text-primary focus:ring-primary"
                                        checked={displayMode === 'all'}
                                        disabled={savingDisplayMode}
                                        onChange={() => submitDisplayMode('all')}
                                    />
                                    <span>
                                        <span className="block font-medium text-gray-900">
                                            Semua
                                        </span>
                                        <span className="block text-sm text-gray-500">
                                            Grid kasir langsung menampilkan
                                            seluruh produk, seperti sekarang.
                                        </span>
                                    </span>
                                </label>

                                <label className="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 p-3 hover:bg-gray-50">
                                    <input
                                        type="radio"
                                        name="product_display_mode"
                                        className="mt-1 text-primary focus:ring-primary"
                                        checked={displayMode === 'search_only'}
                                        disabled={savingDisplayMode}
                                        onChange={() => submitDisplayMode('search_only')}
                                    />
                                    <span>
                                        <span className="block font-medium text-gray-900">
                                            Terbatas
                                        </span>
                                        <span className="block text-sm text-gray-500">
                                            Grid kasir kosong sampai kasir
                                            mengetik pencarian. Cocok untuk
                                            katalog produk yang sangat besar.
                                            Scan barcode tetap berfungsi
                                            normal tanpa perlu mencari dulu.
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div className="mt-6 space-y-3 border-t border-gray-100 pt-4">
                                <label className="flex cursor-pointer items-start gap-3">
                                    <input
                                        type="checkbox"
                                        className="mt-1 rounded text-primary focus:ring-primary"
                                        checked={stockOnButton}
                                        disabled={savingKasirDisplay}
                                        onChange={(e) =>
                                            submitKasirDisplay({
                                                stockOnButton: e.target.checked,
                                                productImage,
                                            })
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium text-gray-900">
                                            Tampilkan stok di tombol produk
                                        </span>
                                        <span className="block text-sm text-gray-500">
                                            Kasir langsung melihat sisa
                                            stok yang bisa dijual di setiap
                                            tombol produk.
                                        </span>
                                    </span>
                                </label>

                                <label className="flex cursor-pointer items-start gap-3">
                                    <input
                                        type="checkbox"
                                        className="mt-1 rounded text-primary focus:ring-primary"
                                        checked={productImage}
                                        disabled={savingKasirDisplay}
                                        onChange={(e) =>
                                            submitKasirDisplay({
                                                stockOnButton,
                                                productImage: e.target.checked,
                                            })
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium text-gray-900">
                                            Tampilkan gambar produk di tombol
                                        </span>
                                        <span className="block text-sm text-gray-500">
                                            Menyiapkan tempatnya saja untuk
                                            sekarang — fitur unggah gambar
                                            produk belum ada, jadi tombol
                                            hanya menampilkan kotak kosong
                                            sampai fitur itu dibuat.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Identitas Toko
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm text-gray-600">
                                Dicetak di bagian atas struk (kasir web &
                                aplikasi mobile). Boleh dikosongkan.
                            </p>

                            <form onSubmit={submitStoreIdentity} className="mt-4 space-y-4">
                                <div>
                                    <InputLabel htmlFor="store_name" value="Nama Toko" />
                                    <TextInput
                                        id="store_name"
                                        className="mt-1 block w-full"
                                        value={storeForm.store_name}
                                        onChange={(e) =>
                                            setStoreForm((prev) => ({
                                                ...prev,
                                                store_name: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="store_address" value="Alamat" />
                                    <TextInput
                                        id="store_address"
                                        className="mt-1 block w-full"
                                        value={storeForm.store_address}
                                        onChange={(e) =>
                                            setStoreForm((prev) => ({
                                                ...prev,
                                                store_address: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="store_phone" value="Telepon" />
                                    <TextInput
                                        id="store_phone"
                                        className="mt-1 block w-full"
                                        value={storeForm.store_phone}
                                        onChange={(e) =>
                                            setStoreForm((prev) => ({
                                                ...prev,
                                                store_phone: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <PrimaryButton type="submit" disabled={savingStore}>
                                    Simpan Identitas Toko
                                </PrimaryButton>
                            </form>
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Struk
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm text-gray-600">
                                Baris teks di bagian paling bawah struk.
                            </p>

                            <form onSubmit={submitReceiptFooter} className="mt-4 space-y-4">
                                <div>
                                    <InputLabel htmlFor="receipt_footer" value="Footer Struk" />
                                    <textarea
                                        id="receipt_footer"
                                        rows={2}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                                        placeholder="Terima kasih atas kunjungan Anda"
                                        value={footerForm}
                                        onChange={(e) => setFooterForm(e.target.value)}
                                    />
                                </div>
                                <PrimaryButton type="submit" disabled={savingFooter}>
                                    Simpan Footer Struk
                                </PrimaryButton>
                            </form>

                            <div className="mt-6 border-t border-gray-100 pt-4">
                                <label className="flex cursor-pointer items-start gap-3">
                                    <input
                                        type="checkbox"
                                        className="mt-1 rounded text-primary focus:ring-primary"
                                        checked={printReceipt}
                                        disabled={savingPrintReceipt}
                                        onChange={(e) =>
                                            submitMobilePrintReceipt(e.target.checked)
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium text-gray-900">
                                            Cetak struk otomatis di HP kasir
                                        </span>
                                        <span className="block text-sm text-gray-500">
                                            Matikan kalau toko sedang tidak
                                            membawa printer (mis. jualan di
                                            luar) — checkout di HP tidak akan
                                            mencoba mencetak sama sekali,
                                            transaksi tetap tersimpan normal.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Nominal Pembayaran Cepat
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm text-gray-600">
                                Tombol pintasan nominal "Uang Diterima" di
                                kasir (web & aplikasi mobile) — tap sebuah
                                nominal langsung MENGISI (bukan menambah)
                                uang diterima ke nilai itu.
                            </p>

                            <form onSubmit={submitQuickAmounts} className="mt-4 space-y-3">
                                {quickAmounts.map((amount, index) => (
                                    <div key={index} className="flex items-center gap-2">
                                        <span className="text-sm text-gray-500">Rp</span>
                                        <input
                                            type="number"
                                            min="1"
                                            step="1"
                                            className="block w-40 rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                                            value={amount}
                                            onChange={(e) =>
                                                updateQuickAmount(index, e.target.value)
                                            }
                                        />
                                        <DangerButton
                                            type="button"
                                            onClick={() => removeQuickAmount(index)}
                                        >
                                            Hapus
                                        </DangerButton>
                                    </div>
                                ))}
                                {quickAmounts.length === 0 && (
                                    <p className="text-sm text-gray-500">
                                        Belum ada nominal — kasir tidak akan
                                        melihat tombol pintasan sama sekali.
                                    </p>
                                )}
                                <InputError
                                    message={
                                        quickAmountsErrors.payment_quick_amounts
                                    }
                                />

                                <div className="flex items-center gap-3 pt-2">
                                    <SecondaryButton
                                        type="button"
                                        onClick={addQuickAmount}
                                        disabled={quickAmounts.length >= 8}
                                    >
                                        Tambah Nominal
                                    </SecondaryButton>
                                    <PrimaryButton
                                        type="submit"
                                        disabled={
                                            savingQuickAmounts ||
                                            quickAmounts.length === 0
                                        }
                                    >
                                        Simpan Nominal
                                    </PrimaryButton>
                                </div>
                                <p className="text-xs text-gray-400">
                                    Maksimal 8 nominal, semua harus angka
                                    positif dan berbeda satu sama lain.
                                </p>
                            </form>
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Member
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 rounded text-primary focus:ring-primary"
                                    checked={memberOn}
                                    disabled={savingMemberEnabled}
                                    onChange={(e) =>
                                        submitMemberEnabled(e.target.checked)
                                    }
                                />
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        Aktifkan fitur Member/Pelanggan
                                    </span>
                                    <span className="block text-sm text-gray-500">
                                        Kalau aktif, kasir (web & aplikasi
                                        mobile) bisa mengisi nama pelanggan
                                        saat checkout — bebas ketik atau pilih
                                        dari daftar Member — dan nama itu akan
                                        tampil di struk. Kalau mati, field
                                        pelanggan tidak muncul sama sekali di
                                        kasir maupun struk, dan data Member
                                        tidak ikut disinkronkan ke aplikasi
                                        mobile.
                                    </span>
                                </span>
                            </label>
                            {memberOn && (
                                <div className="mt-4 border-t border-gray-100 pt-4">
                                    <Link href={route('master.members.index')}>
                                        <SecondaryButton type="button">
                                            Kelola Member
                                        </SecondaryButton>
                                    </Link>
                                </div>
                            )}
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Meja
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 rounded text-primary focus:ring-primary"
                                    checked={tableOn}
                                    disabled={savingTableEnabled}
                                    onChange={(e) =>
                                        submitTableEnabled(e.target.checked)
                                    }
                                />
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        Aktifkan fitur Nomor Meja
                                    </span>
                                    <span className="block text-sm text-gray-500">
                                        Kalau aktif, kasir (web & aplikasi
                                        mobile) bisa mengisi nomor meja saat
                                        checkout — bebas ketik atau pilih dari
                                        daftar Meja — dan nomornya akan tampil
                                        di struk. Kalau mati, field meja tidak
                                        muncul sama sekali di kasir maupun
                                        struk, dan data Meja tidak ikut
                                        disinkronkan ke aplikasi mobile.
                                    </span>
                                </span>
                            </label>
                            {tableOn && (
                                <div className="mt-4 border-t border-gray-100 pt-4">
                                    <Link href={route('master.tables.index')}>
                                        <SecondaryButton type="button">
                                            Kelola Meja
                                        </SecondaryButton>
                                    </Link>
                                </div>
                            )}
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Catatan
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 rounded text-primary focus:ring-primary"
                                    checked={noteOn}
                                    disabled={savingNoteEnabled}
                                    onChange={(e) =>
                                        submitNoteEnabled(e.target.checked)
                                    }
                                />
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        Aktifkan fitur Catatan
                                    </span>
                                    <span className="block text-sm text-gray-500">
                                        Kalau aktif, kasir (web & aplikasi
                                        mobile) bisa menambah catatan
                                        per-item (mis. "es sedikit") maupun
                                        per-transaksi (mis. "antar ke meja
                                        5") — bebas ketik atau tap dari
                                        Template Catatan — dan tampil di
                                        struk. Kalau mati, field catatan
                                        tidak muncul sama sekali di kasir
                                        maupun struk, dan template catatan
                                        tidak ikut disinkronkan ke aplikasi
                                        mobile.
                                    </span>
                                </span>
                            </label>
                            {noteOn && (
                                <div className="mt-4 border-t border-gray-100 pt-4">
                                    <Link href={route('master.note-templates.index')}>
                                        <SecondaryButton type="button">
                                            Kelola Template Catatan
                                        </SecondaryButton>
                                    </Link>
                                </div>
                            )}
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Variasi
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 rounded text-primary focus:ring-primary"
                                    checked={variationOn}
                                    disabled={savingVariationEnabled}
                                    onChange={(e) =>
                                        submitVariationEnabled(e.target.checked)
                                    }
                                />
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        Aktifkan fitur Variasi Berbayar
                                    </span>
                                    <span className="block text-sm text-gray-500">
                                        Kalau aktif, produk yang punya
                                        variasi (mis. "Gelas Besar" +2.000)
                                        menampilkan pemilih variasi saat
                                        ditambah ke keranjang (web & aplikasi
                                        mobile) — kasir bisa memilih lebih
                                        dari satu variasi sekaligus, harga
                                        baris otomatis bertambah. Kalau mati,
                                        pemilih variasi tidak muncul sama
                                        sekali, produk langsung masuk
                                        keranjang seperti biasa. Tahap 1:
                                        variasi baru menambah harga jual,
                                        belum memengaruhi stok/HPP. Kelola
                                        variasi tiap produk di halaman Master
                                        &gt; Produk.
                                    </span>
                                </span>
                            </label>
                            {variationOn && (
                                <div className="mt-4 border-t border-gray-100 pt-4">
                                    <Link href={route('master.products.index')}>
                                        <SecondaryButton type="button">
                                            Kelola Produk &amp; Variasi
                                        </SecondaryButton>
                                    </Link>
                                </div>
                            )}
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Draft
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 rounded text-primary focus:ring-primary"
                                    checked={draftOn}
                                    disabled={savingDraftEnabled}
                                    onChange={(e) =>
                                        submitDraftEnabled(e.target.checked)
                                    }
                                />
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        Aktifkan fitur Draft
                                    </span>
                                    <span className="block text-sm text-gray-500">
                                        Kalau aktif, kasir di aplikasi mobile
                                        bisa menyimpan pesanan yang belum
                                        final sebagai draft (mis. per meja),
                                        melanjutkan/mengeditnya nanti, baru
                                        membayarnya jadi transaksi sungguhan.
                                        Draft tersimpan LOKAL di HP masing-
                                        masing kasir — tidak menyentuh stok
                                        atau jurnal sampai benar-benar
                                        dibayar. Kalau mati, checkout selalu
                                        langsung seperti biasa, tidak ada
                                        tombol/daftar draft yang muncul.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            QRIS
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 rounded text-primary focus:ring-primary"
                                    checked={qrisOn}
                                    disabled={savingQris || bankAccounts.length === 0}
                                    onChange={(e) =>
                                        submitQris({ qrisOn: e.target.checked })
                                    }
                                />
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        Aktifkan metode pembayaran QRIS
                                    </span>
                                    <span className="block text-sm text-gray-500">
                                        Pencatatan saja — TIDAK ada QR
                                        dinamis/integrasi payment gateway.
                                        Toko tetap pakai QR statis yang sudah
                                        dicetak dari bank/e-wallet sendiri;
                                        kasir cuma mengonfirmasi "sudah
                                        dibayar" setelah pelanggan scan &amp;
                                        transfer. Uang QRIS dicatat masuk ke
                                        akun Bank di bawah (BUKAN Kas), sama
                                        seperti transfer bank biasa. Kalau
                                        mati, opsi QRIS tidak muncul sama
                                        sekali di kasir manapun.
                                    </span>
                                </span>
                            </label>

                            {bankAccounts.length === 0 ? (
                                <p className="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-500">
                                    Belum ada akun Bank.{' '}
                                    <Link
                                        href={route('kas-bank.accounts.index')}
                                        className="text-primary hover:underline"
                                    >
                                        Tambah akun Bank
                                    </Link>{' '}
                                    dulu sebelum mengaktifkan QRIS — uang
                                    QRIS harus mendarat di rekening bank,
                                    bukan Kas.
                                </p>
                            ) : (
                                <div className="mt-4 border-t border-gray-100 pt-4">
                                    <InputLabel
                                        htmlFor="qris_cash_account_code"
                                        value="Akun Bank tujuan QRIS"
                                    />
                                    <SelectInput
                                        id="qris_cash_account_code"
                                        className="mt-1 h-10 block w-full max-w-xs"
                                        value={qrisAccountCode}
                                        disabled={savingQris}
                                        onChange={(e) =>
                                            submitQris({
                                                qrisAccountCode: e.target.value,
                                            })
                                        }
                                    >
                                        {bankAccounts.map((account) => (
                                            <option
                                                key={account.code}
                                                value={account.code}
                                            >
                                                {account.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError
                                        message={qrisErrors.qris_cash_account_code}
                                        className="mt-1"
                                    />
                                </div>
                            )}
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Multi-Cabang
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 rounded text-primary focus:ring-primary"
                                    checked={multiBranchOn}
                                    disabled={savingMultiBranch}
                                    onChange={(e) =>
                                        submitMultiBranchEnabled(e.target.checked)
                                    }
                                />
                                <span>
                                    <span className="block font-medium text-gray-900">
                                        Aktifkan fitur Multi-Cabang
                                    </span>
                                    <span className="block text-sm text-gray-500">
                                        Kalau aktif, menu "Cabang" muncul
                                        (kelola daftar cabang, tandai
                                        cabang pusat) dan pemilih cabang
                                        muncul di halaman Kelola Pengguna
                                        &amp; Kelola Perangkat. Kalau mati
                                        (default, cocok untuk toko satu
                                        lokasi), semuanya tersembunyi —
                                        tidak ada yang berubah dari
                                        perilaku sekarang.
                                    </span>
                                </span>
                            </label>
                            {multiBranchOn && (
                                <div className="mt-4 border-t border-gray-100 pt-4">
                                    <Link href={route('master.outlets.index')}>
                                        <SecondaryButton type="button">
                                            Kelola Cabang
                                        </SecondaryButton>
                                    </Link>
                                </div>
                            )}
                        </div>
                    </section>

                    <hr className="border-gray-200" />

                    <section>
                        <h3 className="mb-3 text-base font-semibold text-gray-900">
                            Device Binding — Grace Period
                        </h3>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm text-gray-600">
                                Selama jendela ini aktif, perangkat mobile
                                BARU (belum pernah tercatat) otomatis
                                disetujui saat login pertama — tidak perlu
                                persetujuan admin manual. Dipakai supaya HP/
                                tablet yang sudah dipakai sekarang tidak
                                tiba-tiba terblokir saat APK dengan Device
                                Binding disebar. Setelah jendela ini berakhir
                                (atau dimatikan), perangkat baru selalu
                                menunggu persetujuan di halaman Kelola
                                Perangkat.
                            </p>

                            <div
                                className={
                                    'mt-4 rounded-md p-3 text-sm ' +
                                    (deviceBindingGracePeriodActive
                                        ? 'bg-green-50 text-green-800 ring-1 ring-green-200'
                                        : 'bg-gray-100 text-gray-600 ring-1 ring-gray-300')
                                }
                            >
                                {deviceBindingGracePeriodActive
                                    ? `Aktif sampai ${formatDateTime(deviceBindingGracePeriodEndsAt)} WIB.`
                                    : 'Tidak aktif — perangkat baru selalu menunggu persetujuan admin.'}
                            </div>

                            <form
                                onSubmit={submitGracePeriodExtend}
                                className="mt-4 flex flex-wrap items-end gap-3"
                            >
                                <div>
                                    <InputLabel
                                        htmlFor="grace_days"
                                        value="Perpanjang dari sekarang (hari)"
                                    />
                                    <TextInput
                                        id="grace_days"
                                        type="number"
                                        min="1"
                                        max="365"
                                        className="mt-1 h-10 block w-32"
                                        value={graceDays}
                                        onChange={(e) => setGraceDays(e.target.value)}
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={gracePeriodErrors.days}
                                    />
                                </div>
                                <PrimaryButton
                                    type="submit"
                                    className="h-10"
                                    disabled={savingGracePeriod}
                                >
                                    Perpanjang
                                </PrimaryButton>
                                <SecondaryButton
                                    type="button"
                                    className="h-10"
                                    disabled={savingGracePeriod || !deviceBindingGracePeriodActive}
                                    onClick={submitGracePeriodDisable}
                                >
                                    Matikan Sekarang
                                </SecondaryButton>
                            </form>
                        </div>
                    </section>
                </div>
            </div>

            <Modal show={confirming} onClose={() => setConfirming(false)}>
                <div className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        {pendingValue
                            ? 'Aktifkan PPN?'
                            : 'Nonaktifkan PPN?'}
                    </h2>

                    <p className="mt-2 text-sm text-gray-600">
                        Perubahan ini akan berlaku untuk{' '}
                        <strong>transaksi berikutnya saja</strong> — baik di
                        kasir web maupun aplikasi mobile. Transaksi dan jurnal
                        yang sudah tersimpan{' '}
                        <strong>tidak akan diubah</strong>, tetap sesuai
                        kondisi PPN saat transaksi itu dibuat.
                    </p>

                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setConfirming(false)}>
                            Batal
                        </SecondaryButton>
                        <PrimaryButton onClick={submit} disabled={processing}>
                            {pendingValue ? 'Ya, Aktifkan' : 'Ya, Nonaktifkan'}
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
