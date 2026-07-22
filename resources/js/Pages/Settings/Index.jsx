import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
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
