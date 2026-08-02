import InputLabel from '@/Components/InputLabel';
import MemberCombobox from '@/Components/MemberCombobox';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import ProductImage from '@/Components/ProductImage';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import VariationPickerModal from '@/Components/VariationPickerModal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(value).toLocaleString('id-ID');

export default function Index({
    products,
    ppnActive,
    productDisplayMode,
    productStock,
    showStockOnButton,
    showProductImage,
    paymentQuickAmounts,
    cashAccounts,
    memberEnabled,
    tableEnabled,
    tables,
    noteEnabled,
    noteTemplates,
    variationEnabled,
}) {
    const [cart, setCart] = useState([]);
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);
    const [barcodeInput, setBarcodeInput] = useState('');
    const [scanError, setScanError] = useState('');
    // memberId nonaktif (null) setiap kali nama diketik ulang manual setelah
    // memilih dari daftar -- teksnya sendiri tetap dipakai sebagai nama
    // pelanggan bebas, lihat MemberCombobox.
    const [memberName, setMemberName] = useState('');
    const [memberId, setMemberId] = useState(null);
    // Meja dipilih dari daftar SAJA (bukan ketik bebas seperti Pelanggan)
    // -- jumlah meja tetap/terbatas, jadi tidak ada risiko "salah ketik"
    // yang perlu diakomodasi lewat teks bebas. '' berarti "tanpa meja".
    const [tableId, setTableId] = useState('');
    // Catatan per-transaksi -- ketik bebas ATAU tap chip template (chip
    // MENGISI field, tidak mengunci; kasir masih bisa mengedit setelahnya).
    const [transactionNote, setTransactionNote] = useState('');
    // `key` baris keranjang (BUKAN product_id lagi -- lihat computeLineKey)
    // yang editor catatannya sedang terbuka -- hanya SATU baris sekaligus
    // (bukan Set) supaya tampilan keranjang yang sudah padat tidak
    // sekaligus melebar untuk banyak baris.
    const [openNoteFor, setOpenNoteFor] = useState(null);
    // Modal pemilih variasi -- null berarti tertutup. `editingLineKey` null
    // berarti sedang MENAMBAH item baru (tap tombol produk); terisi berarti
    // sedang MENGEDIT variasi baris keranjang yang sudah ada (lihat
    // openVariationEditor()).
    const [variationPicker, setVariationPicker] = useState(null);
    // String desimal biasa ("plain", bukan tampilan berformat) -- ini yang
    // dikirim NumberInput lewat onChange, siap dipakai langsung sebagai
    // cash_received saat checkout tanpa parsing pemisah ribuan lagi.
    const [cashReceivedInput, setCashReceivedInput] = useState('');
    // Default Kas -- kasir cuma perlu ganti kalau uang benar-benar masuk
    // ke rekening bank (mis. dibayar QRIS/transfer), bukan laci tunai.
    const [cashAccountCode, setCashAccountCode] = useState(cashAccounts[0]?.code ?? '');
    const barcodeRef = useRef(null);

    // Transaksi yang baru saja disimpan -- dipakai untuk menawarkan
    // "Cetak Struk" tanpa memaksa kasir pindah dari layar ini (link struk
    // dibuka di tab baru, lihat JSX di bawah). `flash.sale_id` cuma terisi
    // SEKALI tepat setelah redirect checkout berhasil (siklus flash session
    // Laravel yang sama seperti flash.success/error), lalu kosong lagi di
    // kunjungan berikutnya -- disalin ke state lokal supaya banner-nya bisa
    // ditutup manual tanpa menunggu flash itu sendiri hilang.
    const { flash } = usePage().props;
    const [lastSaleId, setLastSaleId] = useState(null);
    useEffect(() => {
        if (flash?.sale_id) {
            setLastSaleId(flash.sale_id);
        }
    }, [flash?.sale_id]);

    const searchTerm = search.trim();
    // Mode 'search_only': grid TIDAK menunggu apa pun selain pencarian --
    // scan barcode sengaja tidak "membuka" grid, karena scan langsung
    // menambah ke keranjang tanpa perlu produk itu terlihat di grid dulu
    // (lihat scanBarcode/addToCart, keduanya bekerja terhadap `products`
    // penuh, bukan `filteredProducts`).
    const awaitingSearch = productDisplayMode === 'search_only' && searchTerm === '';

    const filteredProducts = useMemo(() => {
        if (awaitingSearch) return [];
        const term = searchTerm.toLowerCase();
        if (!term) return products;
        return products.filter((p) => p.name.toLowerCase().includes(term));
    }, [products, searchTerm, awaitingSearch]);

    const productsById = useMemo(
        () => new Map(products.map((p) => [p.id, p])),
        [products],
    );

    // Fokus otomatis ke kolom barcode saat halaman dibuka, supaya scanner
    // (yang berperilaku seperti keyboard) bisa langsung dipakai tanpa kasir
    // harus klik apa pun dulu.
    useEffect(() => {
        barcodeRef.current?.focus();
    }, []);

    const scanBarcode = (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();

        const code = barcodeInput.trim();
        setBarcodeInput('');
        if (!code) return;

        const product = products.find((p) => p.barcode === code);
        if (product) {
            addToCart(product);
            setScanError('');
        } else {
            setScanError(`Barcode "${code}" tidak ditemukan.`);
        }

        barcodeRef.current?.focus();
    };

    // Harga baris = harga produk + jumlah additional_price tiap variasi
    // TERCENTANG -- satu-satunya tempat rumus ini hidup di sisi klien,
    // dipakai baik saat menambah item baru maupun mengedit variasi item
    // yang sudah ada di keranjang (lihat handleVariationConfirm).
    const computeUnitPrice = (product, variations) =>
        Number(product.sell_price) +
        variations.reduce((sum, v) => sum + Number(v.additional_price), 0);

    // Kunci identitas baris keranjang = produk + kombinasi variasi
    // terpilih (terurut, supaya "Besar+Panas" dan "Panas+Besar" dianggap
    // baris yang SAMA). BEDA dari sebelum fitur ini ada (kunci = product_id
    // saja): "Es Teh + Gelas Besar" dan "Es Teh + Gelas Kecil" sekarang
    // harus jadi DUA baris terpisah, sementara "Es Teh + Gelas Besar" yang
    // ditambah dua kali tetap satu baris (qty bertambah).
    const computeLineKey = (productId, variationIds) =>
        `${productId}:${[...variationIds].sort((a, b) => a - b).join(',')}`;

    const addToCartWithVariations = (product, variations) => {
        const key = computeLineKey(
            product.id,
            variations.map((v) => v.id),
        );
        setCart((prev) => {
            const existing = prev.find((line) => line.key === key);
            if (existing) {
                return prev.map((line) =>
                    line.key === key
                        ? { ...line, qty: line.qty + 1 }
                        : line,
                );
            }
            return [
                ...prev,
                {
                    key,
                    product_id: product.id,
                    name: product.name,
                    qty: 1,
                    unit_price: computeUnitPrice(product, variations),
                    tax_rate: product.tax_rate
                        ? Number(product.tax_rate.rate)
                        : 0,
                    note: '',
                    variations,
                },
            ];
        });
    };

    // Gerbang utama: produk TANPA variasi aktif (atau fitur mati) langsung
    // masuk keranjang -- NOL perubahan kecepatan dari sebelum fitur ini
    // ada. Dialog variasi HANYA muncul untuk produk yang benar-benar punya
    // variasi aktif & fitur menyala. Dipanggil dari tombol grid MAUPUN
    // scanBarcode() -- keduanya lewat jalur yang sama, konsisten terlepas
    // dari cara produk ditemukan.
    const addToCart = (product) => {
        const activeVariations = (product.variations ?? []).filter(
            (v) => v.is_active,
        );
        if (variationEnabled && activeVariations.length > 0) {
            setVariationPicker({
                product,
                editingLineKey: null,
                initialSelectedIds: [],
            });
            return;
        }
        addToCartWithVariations(product, []);
    };

    // Tap area variasi pada baris keranjang yang sudah ada -- membuka ulang
    // modal yang SAMA, pre-filled dari pilihan saat ini (pola identik
    // editor catatan per-item: tap untuk mengedit, bukan hapus-tambah ulang).
    const openVariationEditor = (line) => {
        const product = productsById.get(line.product_id);
        if (!product) return;
        setVariationPicker({
            product,
            editingLineKey: line.key,
            initialSelectedIds: line.variations.map((v) => v.id),
        });
    };

    const handleVariationConfirm = (selectedVariations) => {
        if (!variationPicker) return;
        const { product, editingLineKey } = variationPicker;

        if (editingLineKey === null) {
            addToCartWithVariations(product, selectedVariations);
        } else {
            // Edit di tempat -- kunci baris ikut berubah kalau kombinasi
            // variasinya berubah, TAPI tidak digabung ke baris lain yang
            // kebetulan sudah punya kombinasi sama (sengaja sederhana,
            // sama seperti editor catatan tidak menggabung baris).
            setCart((prev) =>
                prev.map((line) => {
                    if (line.key !== editingLineKey) return line;
                    return {
                        ...line,
                        key: computeLineKey(
                            line.product_id,
                            selectedVariations.map((v) => v.id),
                        ),
                        variations: selectedVariations,
                        unit_price: computeUnitPrice(
                            product,
                            selectedVariations,
                        ),
                    };
                }),
            );
        }
        setVariationPicker(null);
    };

    const updateNote = (lineKey, note) => {
        setCart((prev) =>
            prev.map((line) =>
                line.key === lineKey ? { ...line, note } : line,
            ),
        );
    };

    const updateQty = (lineKey, qty) => {
        const parsed = Math.max(0, Number(qty) || 0);
        setCart((prev) =>
            prev
                .map((line) =>
                    line.key === lineKey ? { ...line, qty: parsed } : line,
                )
                .filter((line) => line.qty > 0),
        );
    };

    const removeLine = (lineKey) => {
        setCart((prev) => prev.filter((line) => line.key !== lineKey));
    };

    // Perkiraan tampilan saja — total resmi selalu dihitung ulang di server
    // (SaleService, pakai bcmath) saat checkout. Harga sudah tax-inclusive:
    // PPN diurai DARI DALAM harga (line ÷ (1+rate)), bukan ditambahkan di
    // atasnya — grand total selalu sama dengan harga yang tertera.
    const totals = useMemo(() => {
        let subtotal = 0;
        let tax = 0;
        let grandTotal = 0;

        for (const l of cart) {
            const lineInclusive = l.qty * l.unit_price;
            const taxed = ppnActive && l.tax_rate > 0;
            const lineNet = taxed ? lineInclusive / (1 + l.tax_rate) : lineInclusive;
            const lineTax = lineInclusive - lineNet;

            subtotal += lineNet;
            tax += lineTax;
            grandTotal += lineInclusive;
        }

        return { subtotal, tax, grandTotal };
    }, [cart, ppnActive]);

    // null (bukan 0) kalau field kosong/bukan angka -- beda dari "kasir
    // sengaja mengetik 0", supaya tombol Bayar nonaktif dengan alasan yang
    // benar ("belum diisi" vs "kurang").
    const cashReceived = useMemo(() => {
        const trimmed = cashReceivedInput.trim();
        if (trimmed === '') return null;
        const parsed = Number(trimmed);
        return Number.isFinite(parsed) ? parsed : null;
    }, [cashReceivedInput]);

    const changeAmount =
        cashReceived === null ? null : Math.max(0, Math.round(cashReceived - totals.grandTotal));

    const isPaymentValid =
        cashReceived !== null && cashReceived >= Math.round(totals.grandTotal);

    const setQuickAmount = (amount) => setCashReceivedInput(String(amount));
    const setExactAmount = () => setCashReceivedInput(String(Math.round(totals.grandTotal)));

    const checkout = () => {
        if (cart.length === 0 || processing || !isPaymentValid) return;

        setProcessing(true);
        router.post(
            route('kasir.store'),
            {
                payment_method: 'cash',
                cash_received: cashReceived,
                change_amount: changeAmount,
                cash_account_code: cashAccountCode,
                member_id: memberEnabled ? memberId : null,
                member_name: memberEnabled && memberName.trim() !== '' ? memberName.trim() : null,
                table_id: tableEnabled && tableId !== '' ? tableId : null,
                note:
                    noteEnabled && transactionNote.trim() !== ''
                        ? transactionNote.trim()
                        : null,
                lines: cart.map((line) => ({
                    product_id: line.product_id,
                    qty: line.qty,
                    unit_price: line.unit_price,
                    note:
                        noteEnabled && line.note?.trim()
                            ? line.note.trim()
                            : null,
                    // unit_price di atas SUDAH termasuk additional_price
                    // tiap variasi ini (lihat computeUnitPrice) -- array
                    // ini murni rincian snapshot untuk nota, server TIDAK
                    // menghitung ulang harga darinya.
                    variations: (line.variations ?? []).map((v) => ({
                        variation_id: v.id,
                        name: v.name,
                        price: v.additional_price,
                    })),
                })),
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setCart([]);
                    setCashReceivedInput('');
                    setMemberName('');
                    setMemberId(null);
                    setTableId('');
                    setTransactionNote('');
                    setOpenNoteFor(null);
                    setVariationPicker(null);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Kasir
                </h2>
            }
        >
            <Head title="Kasir" />

            <div className="py-12">
                <div className="mx-auto grid max-w-7xl grid-cols-1 gap-6 sm:px-6 lg:grid-cols-3 lg:px-8">
                    <div className="space-y-4 lg:col-span-2">
                        <div>
                            <TextInput
                                ref={barcodeRef}
                                placeholder="Scan barcode di sini…"
                                className="w-full border-accent/50 bg-accent/5 focus:border-primary focus:ring-primary"
                                value={barcodeInput}
                                onChange={(e) => {
                                    setBarcodeInput(e.target.value);
                                    setScanError('');
                                }}
                                onKeyDown={scanBarcode}
                            />
                            {scanError && (
                                <p className="mt-1 text-sm text-red-600">
                                    {scanError}
                                </p>
                            )}
                        </div>

                        <TextInput
                            placeholder="Cari produk..."
                            className="w-full"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />

                        {awaitingSearch ? (
                            <div className="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-accent/40 bg-accent/5 px-6 py-12 text-center">
                                <span className="text-3xl">🔍</span>
                                <p className="text-base font-medium text-gray-700">
                                    Ketik nama produk di kotak pencarian
                                    untuk mulai
                                </p>
                                <p className="text-sm text-gray-500">
                                    Katalog produk di toko ini cukup besar,
                                    jadi grid baru muncul setelah Anda
                                    mencari. Scan barcode tetap langsung
                                    berfungsi tanpa perlu mencari dulu.
                                </p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {filteredProducts.map((product) => {
                                    // null berarti "tidak dibatasi stok"
                                    // (lihat InventoryService::producibleQtyForProducts)
                                    // -- sengaja tidak menampilkan badge sama
                                    // sekali, bukan "0" atau "∞".
                                    const stockQty = showStockOnButton
                                        ? productStock?.[product.id]
                                        : undefined;
                                    return (
                                        <button
                                            key={product.id}
                                            type="button"
                                            onClick={() => addToCart(product)}
                                            className="rounded-lg border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-accent hover:shadow"
                                        >
                                            {showProductImage && (
                                                <ProductImage
                                                    src={product.image_url}
                                                    name={product.name}
                                                    className="mb-2 h-16 w-full"
                                                />
                                            )}
                                            <div className="font-medium text-gray-900">
                                                {product.name}
                                            </div>
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-sm text-gray-500">
                                                    {formatRupiah(product.sell_price)}
                                                </span>
                                                {stockQty !== undefined && stockQty !== null && (
                                                    <span
                                                        className={
                                                            'shrink-0 rounded-full px-1.5 py-0.5 text-xs font-medium ' +
                                                            (stockQty > 0
                                                                ? 'bg-green-100 text-green-700'
                                                                : 'bg-red-100 text-red-700')
                                                        }
                                                    >
                                                        Stok {stockQty}
                                                    </span>
                                                )}
                                            </div>
                                        </button>
                                    );
                                })}
                                {filteredProducts.length === 0 && (
                                    <p className="col-span-full text-sm text-gray-500">
                                        Produk tidak ditemukan.
                                    </p>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="h-fit rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <h3 className="mb-3 font-semibold text-gray-900">
                            Keranjang
                        </h3>

                        <div className="space-y-3">
                            {cart.map((line) => {
                                // Ada variasi UNTUK DIEDIT hanya kalau produknya
                                // sendiri masih punya variasi aktif -- kalau
                                // admin menonaktifkan semua variasi produk ini
                                // SETELAH baris ditambahkan, baris lama tetap
                                // menampilkan pilihannya (snapshot) tapi tombol
                                // edit disembunyikan (tidak ada apa pun untuk
                                // dipilih ulang).
                                const product = productsById.get(line.product_id);
                                const hasEditableVariations =
                                    variationEnabled &&
                                    (product?.variations ?? []).some(
                                        (v) => v.is_active,
                                    );
                                return (
                                    <div key={line.key} className="text-sm">
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1">
                                                <div className="font-medium text-gray-900">
                                                    {line.name}
                                                </div>
                                                <div className="text-gray-500">
                                                    {formatRupiah(line.unit_price)} /
                                                    pcs
                                                </div>
                                                {/* Rincian variasi terpilih -- tap
                                                    untuk mengubah (kalau produknya
                                                    masih punya variasi aktif). */}
                                                {line.variations.length > 0 && (
                                                    <button
                                                        type="button"
                                                        disabled={!hasEditableVariations}
                                                        onClick={() =>
                                                            openVariationEditor(line)
                                                        }
                                                        className={
                                                            'block text-left text-gray-500' +
                                                            (hasEditableVariations
                                                                ? ' hover:text-primary hover:underline'
                                                                : '')
                                                        }
                                                    >
                                                        {line.variations
                                                            .map(
                                                                (v) =>
                                                                    `+ ${v.name} (+${formatRupiah(v.additional_price)})`,
                                                            )
                                                            .join(', ')}
                                                    </button>
                                                )}
                                                {hasEditableVariations &&
                                                    line.variations.length === 0 && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                openVariationEditor(line)
                                                            }
                                                            className="block text-left text-accent hover:underline"
                                                        >
                                                            + Tambah variasi
                                                        </button>
                                                    )}
                                                {/* Pratinjau catatan tersimpan --
                                                    muncul saat editor SEDANG
                                                    tertutup, supaya kasir tetap
                                                    lihat isinya tanpa harus
                                                    membuka editor lagi. */}
                                                {noteEnabled &&
                                                    line.note?.trim() &&
                                                    openNoteFor !== line.key && (
                                                        <div className="italic text-gray-500">
                                                            → {line.note.trim()}
                                                        </div>
                                                    )}
                                            </div>
                                            <input
                                                type="number"
                                                min="0"
                                                step="1"
                                                value={line.qty}
                                                onChange={(e) =>
                                                    updateQty(
                                                        line.key,
                                                        e.target.value,
                                                    )
                                                }
                                                className="w-16 rounded-md border-gray-300 text-center shadow-sm focus:border-primary focus:ring-primary"
                                            />
                                            {noteEnabled && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setOpenNoteFor(
                                                            openNoteFor === line.key
                                                                ? null
                                                                : line.key,
                                                        )
                                                    }
                                                    className="px-1 text-lg leading-none text-gray-500 hover:text-primary"
                                                    aria-label="Catatan item"
                                                    title="Catatan item"
                                                >
                                                    📝
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => removeLine(line.key)}
                                                className="px-1 text-lg leading-none text-red-600 hover:text-red-800"
                                                aria-label="Hapus"
                                            >
                                                &times;
                                            </button>
                                        </div>

                                        {noteEnabled && openNoteFor === line.key && (
                                            <div className="mt-2 rounded-md bg-gray-50 p-2">
                                                {noteTemplates.length > 0 && (
                                                    <div className="mb-2 flex flex-wrap gap-1">
                                                        {noteTemplates.map((template) => (
                                                            <button
                                                                key={template.id}
                                                                type="button"
                                                                onClick={() =>
                                                                    updateNote(
                                                                        line.key,
                                                                        template.text,
                                                                    )
                                                                }
                                                                className="rounded-full border border-gray-300 bg-white px-2 py-0.5 text-xs text-gray-700 hover:border-accent hover:text-accent"
                                                            >
                                                                {template.text}
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                                <textarea
                                                    rows={2}
                                                    placeholder="Catatan untuk item ini..."
                                                    value={line.note ?? ''}
                                                    onChange={(e) =>
                                                        updateNote(
                                                            line.key,
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
                                                />
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                            {cart.length === 0 && (
                                <p className="text-sm text-gray-500">
                                    Keranjang kosong.
                                </p>
                            )}
                        </div>

                        {memberEnabled && (
                            <div className="mt-4 border-t border-gray-200 pt-4">
                                <InputLabel htmlFor="member_name" value="Pelanggan" />
                                <MemberCombobox
                                    value={memberName}
                                    onChange={(text) => {
                                        setMemberName(text);
                                        setMemberId(null);
                                    }}
                                    onSelect={(member) => {
                                        setMemberName(member.name);
                                        setMemberId(member.id);
                                    }}
                                    className="mt-1"
                                />
                            </div>
                        )}

                        {tableEnabled && (
                            <div
                                className={
                                    memberEnabled
                                        ? 'mt-4'
                                        : 'mt-4 border-t border-gray-200 pt-4'
                                }
                            >
                                <InputLabel htmlFor="table_id" value="Meja" />
                                <SelectInput
                                    id="table_id"
                                    className="mt-1 h-10 block w-full"
                                    value={tableId}
                                    onChange={(e) => setTableId(e.target.value)}
                                >
                                    <option value="">Tanpa meja</option>
                                    {tables.map((table) => (
                                        <option key={table.id} value={table.id}>
                                            {table.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>
                        )}

                        {noteEnabled && (
                            <div
                                className={
                                    memberEnabled || tableEnabled
                                        ? 'mt-4'
                                        : 'mt-4 border-t border-gray-200 pt-4'
                                }
                            >
                                <InputLabel htmlFor="transaction_note" value="Catatan" />
                                {noteTemplates.length > 0 && (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {noteTemplates.map((template) => (
                                            <button
                                                key={template.id}
                                                type="button"
                                                onClick={() =>
                                                    setTransactionNote(template.text)
                                                }
                                                className="rounded-full border border-gray-300 bg-white px-2 py-0.5 text-xs text-gray-700 hover:border-accent hover:text-accent"
                                            >
                                                {template.text}
                                            </button>
                                        ))}
                                    </div>
                                )}
                                <textarea
                                    id="transaction_note"
                                    rows={2}
                                    placeholder="mis. Antar ke meja 5"
                                    value={transactionNote}
                                    onChange={(e) =>
                                        setTransactionNote(e.target.value)
                                    }
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
                                />
                            </div>
                        )}

                        <div className="mt-4 space-y-1 border-t border-gray-200 pt-4 text-sm">
                            <div className="flex justify-between text-gray-600">
                                <span>Subtotal</span>
                                <span>{formatRupiah(totals.subtotal)}</span>
                            </div>
                            <div className="flex justify-between text-gray-600">
                                <span>Pajak</span>
                                <span>{formatRupiah(totals.tax)}</span>
                            </div>
                            <div className="flex justify-between font-semibold text-gray-900">
                                <span>Total</span>
                                <span>
                                    {formatRupiah(totals.grandTotal)}
                                </span>
                            </div>
                        </div>

                        {paymentQuickAmounts?.length > 0 && (
                            <div className="mt-4 flex flex-wrap gap-2">
                                {paymentQuickAmounts.map((amount) => (
                                    <button
                                        key={amount}
                                        type="button"
                                        onClick={() => setQuickAmount(amount)}
                                        className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-accent hover:text-accent"
                                    >
                                        {formatRupiah(amount)}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={setExactAmount}
                                    className="rounded-md border border-accent bg-accent/5 px-3 py-1.5 text-sm font-medium text-accent hover:bg-accent/10"
                                >
                                    Uang Pas
                                </button>
                            </div>
                        )}

                        {cashAccounts.length > 1 && (
                            <div className="mt-3">
                                <InputLabel htmlFor="cash_account_code" value="Masuk Ke" />
                                <SelectInput
                                    id="cash_account_code"
                                    className="mt-1 h-10 block w-full"
                                    value={cashAccountCode}
                                    onChange={(e) => setCashAccountCode(e.target.value)}
                                >
                                    {cashAccounts.map((account) => (
                                        <option key={account.code} value={account.code}>
                                            {account.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>
                        )}

                        <div className="mt-3">
                            <InputLabel htmlFor="cash_received" value="Uang Diterima" />
                            <NumberInput
                                id="cash_received"
                                className="mt-1 block w-full"
                                maxDecimals={0}
                                value={cashReceivedInput}
                                onChange={setCashReceivedInput}
                            />
                        </div>

                        <div className="mt-2 flex justify-between text-sm">
                            <span className="text-gray-600">Kembalian</span>
                            <span
                                className={
                                    'font-semibold ' +
                                    (cashReceived !== null && !isPaymentValid
                                        ? 'text-red-600'
                                        : 'text-gray-900')
                                }
                            >
                                {changeAmount === null
                                    ? '-'
                                    : formatRupiah(changeAmount)}
                            </span>
                        </div>
                        {cashReceived !== null && !isPaymentValid && (
                            <p className="text-xs text-red-600">
                                Uang diterima kurang dari total.
                            </p>
                        )}

                        <PrimaryButton
                            className="mt-4 w-full justify-center"
                            disabled={cart.length === 0 || processing || !isPaymentValid}
                            onClick={checkout}
                        >
                            Bayar (Cash)
                        </PrimaryButton>

                        {lastSaleId && (
                            <div className="mt-4 rounded-md border border-green-300 bg-green-50 p-3">
                                <p className="text-sm text-green-800">
                                    Transaksi #{lastSaleId} tersimpan.
                                </p>
                                {/* flex-wrap + flex-1 supaya kedua tombol berbagi
                                    lebar secara rata dan turun ke baris baru kalau
                                    kolom (sidebar kasir) terlalu sempit, bukan
                                    meluber keluar container seperti sebelumnya. */}
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <Link
                                        href={route('penjualan.receipt', lastSaleId)}
                                        target="_blank"
                                        className="flex-1"
                                    >
                                        <PrimaryButton type="button" className="w-full justify-center">
                                            Cetak Struk
                                        </PrimaryButton>
                                    </Link>
                                    <SecondaryButton
                                        type="button"
                                        className="flex-1 justify-center"
                                        onClick={() => setLastSaleId(null)}
                                    >
                                        Tutup
                                    </SecondaryButton>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <VariationPickerModal
                show={variationPicker !== null}
                product={variationPicker?.product ?? null}
                initialSelectedIds={variationPicker?.initialSelectedIds ?? []}
                onConfirm={handleVariationConfirm}
                onClose={() => setVariationPicker(null)}
            />
        </AuthenticatedLayout>
    );
}
