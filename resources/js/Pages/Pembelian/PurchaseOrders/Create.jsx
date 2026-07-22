import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import ItemCombobox from '@/Components/ItemCombobox';
import Modal from '@/Components/Modal';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SupplierCombobox from '@/Components/SupplierCombobox';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const emptyLine = () => ({
    item_id: '',
    purchase_uom_id: '',
    qty: '',
    unit_price: '',
    tax_rate_id: '',
});

const isLineBlank = (line) => !line.item_id && !line.qty && !line.unit_price;

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');

const toNumber = (value) => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
};

// Mengikuti persis rumus PurchaseService::createPurchaseOrder() di backend
// (qty * unit_price, lalu pajak = lineTotal * tax_rate.rate, ditambahkan di
// atas subtotal — BUKAN tax-inclusive) supaya angka pratinjau di sini tidak
// pernah menyimpang dari yang benar-benar disimpan. Ini cuma pratinjau
// real-time (JS float) — angka final tetap dihitung ulang di server dengan
// bcmath saat submit.
function computeLineTotals(line, taxRates) {
    const qty = toNumber(line.qty);
    const unitPrice = toNumber(line.unit_price);
    const lineTotal = qty * unitPrice;
    const taxRate = taxRates.find((t) => String(t.id) === String(line.tax_rate_id));
    const lineTax = taxRate ? lineTotal * toNumber(taxRate.rate) : 0;

    return { lineTotal, lineTax };
}

export default function Create({ warehouses, uoms, taxRates }) {
    const { data, setData, post, processing, errors } = useForm({
        supplier_id: '',
        warehouse_id: warehouses[0]?.id ?? '',
        date: new Date().toISOString().slice(0, 10),
        lines: [emptyLine()],
        notes: '',
    });

    // Supplier & item lengkap — cuma untuk menampilkan label di
    // Supplier/ItemCombobox, tidak dikirim ke server. lineItems selaras
    // indeksnya dengan data.lines. Supplier & katalog item TIDAK pernah
    // dimuat penuh (item bisa puluhan ribu baris) — combobox ini mencari ke
    // server per ketikan.
    const [supplierItem, setSupplierItem] = useState(null);
    const [lineItems, setLineItems] = useState([null]);
    const [showConfirmSave, setShowConfirmSave] = useState(false);
    const formRef = useRef(null);

    const addLine = () => {
        setData('lines', [...data.lines, emptyLine()]);
        setLineItems((previous) => [...previous, null]);
    };

    const removeLine = (index) => {
        setData(
            'lines',
            data.lines.filter((_, i) => i !== index),
        );
        setLineItems((previous) => previous.filter((_, i) => i !== index));
    };

    // Baris kosong (belum diisi apa-apa) dihapus langsung; baris yang sudah
    // ada isinya (item/qty/harga) wajib dikonfirmasi dulu, supaya klik yang
    // tidak sengaja tidak diam-diam membuang isian.
    const removeLineWithConfirmation = (index) => {
        const line = data.lines[index];
        if (!isLineBlank(line) && !confirm('Baris ini sudah terisi. Yakin ingin menghapusnya?')) {
            return;
        }
        removeLine(index);
    };

    const updateLine = (index, field, value) => {
        const updated = data.lines.map((line, i) =>
            i === index ? { ...line, [field]: value } : line,
        );
        setData('lines', updated);
    };

    const selectLineItem = (index, item) => {
        // Bangun seluruh baris dalam satu setData — dua updateLine() berturut
        // akan sama-sama membaca data.lines dari closure basi dan yang kedua
        // menimpa yang pertama (item_id diam-diam hilang).
        const updated = data.lines.map((line, i) => {
            if (i !== index) return line;
            return { ...line, item_id: item.id, purchase_uom_id: item.purchase_uom_id };
        });
        setData('lines', updated);
        setLineItems((previous) =>
            previous.map((existing, i) => (i === index ? item : existing)),
        );
    };

    const selectSupplier = (supplier) => {
        setData('supplier_id', supplier.id);
        setSupplierItem(supplier);
    };

    const totals = data.lines.reduce(
        (acc, line) => {
            const { lineTotal, lineTax } = computeLineTotals(line, taxRates);
            return {
                subtotal: acc.subtotal + lineTotal,
                tax: acc.tax + lineTax,
            };
        },
        { subtotal: 0, tax: 0 },
    );
    const grandTotal = totals.subtotal + totals.tax;

    // Jangan langsung simpan — tampilkan ringkasan dulu (supplier, jumlah
    // baris, total) supaya user bisa memeriksa sebelum PO benar-benar jadi
    // dokumen. Baik tombol Simpan maupun Ctrl+Enter lewat jalur ini karena
    // keduanya memicu submit form yang sama.
    const submit = (e) => {
        e.preventDefault();
        setShowConfirmSave(true);
    };

    const confirmSave = () => {
        setShowConfirmSave(false);
        post(route('pembelian.purchase-orders.store'));
    };

    // Enter di field biasa (qty/harga/tanggal/dst) TIDAK boleh men-submit PO
    // secara tidak sengaja — malah memindahkan fokus ke field berikutnya
    // (seperti Excel/Tab). Pengecualian: Enter di dalam
    // ItemCombobox/SupplierCombobox tetap berarti "pilih hasil highlight" —
    // combobox itu sendiri sudah memanggil preventDefault() saat menangani
    // Enter untuk memilih, jadi cukup cek e.defaultPrevented di sini supaya
    // tidak menimpa perilaku combobox yang sudah benar. Textarea (Catatan)
    // dikecualikan juga — Enter di situ harus tetap menyisipkan baris baru.
    const handleFormKeyDown = (e) => {
        if (
            e.key !== 'Enter' ||
            e.ctrlKey ||
            e.defaultPrevented ||
            e.target.tagName === 'TEXTAREA'
        ) {
            return;
        }

        e.preventDefault();

        const focusable = Array.from(
            formRef.current?.querySelectorAll('input, select') ?? [],
        ).filter((el) => !el.disabled);
        const currentIndex = focusable.indexOf(e.target);
        if (currentIndex === -1) return;

        // Enter di field terakhir sengaja tidak melakukan apa-apa (bukan
        // menambah baris baru secara diam-diam) — user tetap harus memakai
        // F2/tombol Tambah Baris secara sadar.
        focusable[currentIndex + 1]?.focus();
    };

    // Shortcut untuk input cepat tanpa mouse:
    // - F2 = Tambah Baris
    // - Ctrl+Enter = Simpan PO (menampilkan dialog konfirmasi dulu, lihat submit())
    // Sengaja BUKAN F5/F11/F12/Ctrl+W/T/N — itu dikuasai browser
    // (refresh/tab) dan akan mengejutkan user (input hilang tiba-tiba).
    // Aktif juga saat fokus ada di field teks (termasuk ItemCombobox) supaya
    // benar-benar bisa dipakai tanpa pindah tangan ke mouse.
    useEffect(() => {
        const handleKeyDown = (e) => {
            if (e.key === 'F2') {
                e.preventDefault();
                addLine();
            } else if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                formRef.current?.requestSubmit();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [data.lines]);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Buat Purchase Order
                </h2>
            }
        >
            <Head title="Buat PO" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <InputLabel
                                        htmlFor="supplier_id"
                                        value="Supplier"
                                    />
                                    <div className="mt-1">
                                        <SupplierCombobox
                                            initialItem={supplierItem}
                                            onSelect={selectSupplier}
                                        />
                                    </div>
                                    <InputError
                                        className="mt-2"
                                        message={errors.supplier_id}
                                    />
                                </div>

                                <div>
                                    <InputLabel
                                        htmlFor="warehouse_id"
                                        value="Gudang"
                                    />
                                    <SelectInput
                                        id="warehouse_id"
                                        className="mt-1 block w-full"
                                        value={data.warehouse_id}
                                        onChange={(e) =>
                                            setData(
                                                'warehouse_id',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    >
                                        {warehouses.map((warehouse) => (
                                            <option
                                                key={warehouse.id}
                                                value={warehouse.id}
                                            >
                                                {warehouse.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError
                                        className="mt-2"
                                        message={errors.warehouse_id}
                                    />
                                </div>

                                <div>
                                    <InputLabel
                                        htmlFor="date"
                                        value="Tanggal"
                                    />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        className="mt-1 block w-full"
                                        value={data.date}
                                        onChange={(e) =>
                                            setData('date', e.target.value)
                                        }
                                        required
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.date}
                                    />
                                </div>
                            </div>

                            <div>
                                <div className="flex items-center justify-between">
                                    <InputLabel value="Baris Barang" />
                                    <SecondaryButton
                                        type="button"
                                        className="h-10"
                                        onClick={addLine}
                                    >
                                        Tambah Baris{' '}
                                        <span className="ml-1 normal-case font-normal text-gray-400">
                                            (F2)
                                        </span>
                                    </SecondaryButton>
                                </div>
                                <InputError
                                    className="mt-2"
                                    message={errors.lines}
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    Pintasan: F2 tambah baris, Ctrl+Enter
                                    simpan. Enter di sebuah field pindah ke
                                    field berikutnya (tidak menyimpan).
                                </p>

                                <div className="mt-3 space-y-3">
                                    {data.lines.map((line, index) => {
                                        const { lineTotal, lineTax } =
                                            computeLineTotals(line, taxRates);

                                        return (
                                        <div
                                            key={index}
                                            className="rounded-md border border-gray-200 p-3"
                                        >
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1">
                                                <ItemCombobox
                                                    key={
                                                        lineItems[index]?.id ??
                                                        `empty-${index}`
                                                    }
                                                    className="h-10"
                                                    initialItem={
                                                        lineItems[index] ?? null
                                                    }
                                                    onSelect={(item) =>
                                                        selectLineItem(
                                                            index,
                                                            item,
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    className="mt-1"
                                                    message={
                                                        errors[
                                                            `lines.${index}.item_id`
                                                        ]
                                                    }
                                                />
                                            </div>

                                            <div className="w-28">
                                                <NumberInput
                                                    placeholder="Qty"
                                                    className="h-10 block w-full"
                                                    value={line.qty}
                                                    onChange={(plain) =>
                                                        updateLine(
                                                            index,
                                                            'qty',
                                                            plain,
                                                        )
                                                    }
                                                    required
                                                />
                                                <InputError
                                                    className="mt-1"
                                                    message={
                                                        errors[
                                                            `lines.${index}.qty`
                                                        ]
                                                    }
                                                />
                                            </div>

                                            <div className="w-32">
                                                <SelectInput
                                                    className="h-10 block w-full"
                                                    value={
                                                        line.purchase_uom_id
                                                    }
                                                    onChange={(e) =>
                                                        updateLine(
                                                            index,
                                                            'purchase_uom_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option
                                                        value=""
                                                        disabled
                                                    >
                                                        Ukuran
                                                    </option>
                                                    {uoms.map((uom) => (
                                                        <option
                                                            key={uom.id}
                                                            value={uom.id}
                                                        >
                                                            {uom.code}
                                                        </option>
                                                    ))}
                                                </SelectInput>
                                                <InputError
                                                    className="mt-1"
                                                    message={
                                                        errors[
                                                            `lines.${index}.purchase_uom_id`
                                                        ]
                                                    }
                                                />
                                            </div>

                                            <div className="w-32">
                                                <NumberInput
                                                    placeholder="Harga/Ukuran"
                                                    className="h-10 block w-full"
                                                    value={line.unit_price}
                                                    onChange={(plain) =>
                                                        updateLine(
                                                            index,
                                                            'unit_price',
                                                            plain,
                                                        )
                                                    }
                                                    required
                                                />
                                                <InputError
                                                    className="mt-1"
                                                    message={
                                                        errors[
                                                            `lines.${index}.unit_price`
                                                        ]
                                                    }
                                                />
                                            </div>

                                            <div className="w-36">
                                                <SelectInput
                                                    className="h-10 block w-full"
                                                    value={
                                                        line.tax_rate_id
                                                    }
                                                    onChange={(e) =>
                                                        updateLine(
                                                            index,
                                                            'tax_rate_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Tanpa pajak
                                                    </option>
                                                    {taxRates.map(
                                                        (taxRate) => (
                                                            <option
                                                                key={
                                                                    taxRate.id
                                                                }
                                                                value={
                                                                    taxRate.id
                                                                }
                                                            >
                                                                {
                                                                    taxRate.name
                                                                }
                                                            </option>
                                                        ),
                                                    )}
                                                </SelectInput>
                                            </div>

                                            <DangerButton
                                                type="button"
                                                className="h-10"
                                                onClick={() =>
                                                    removeLineWithConfirmation(
                                                        index,
                                                    )
                                                }
                                            >
                                                Hapus
                                            </DangerButton>
                                        </div>

                                        <p className="mt-2 text-xs text-gray-500">
                                            Subtotal baris:{' '}
                                            <span className="font-medium text-gray-700">
                                                {formatRupiah(lineTotal)}
                                            </span>
                                            {lineTax > 0 && (
                                                <>
                                                    {' '}
                                                    + Pajak:{' '}
                                                    <span className="font-medium text-gray-700">
                                                        {formatRupiah(lineTax)}
                                                    </span>
                                                </>
                                            )}
                                        </p>
                                        </div>
                                        );
                                    })}
                                </div>

                                <div className="mt-4 flex justify-end">
                                    <div className="w-full max-w-xs space-y-1 text-sm text-gray-700">
                                        <div className="flex justify-between">
                                            <span>Subtotal</span>
                                            <span>{formatRupiah(totals.subtotal)}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span>Pajak</span>
                                            <span>{formatRupiah(totals.tax)}</span>
                                        </div>
                                        <div className="flex justify-between border-t border-gray-200 pt-1 font-semibold text-gray-900">
                                            <span>Total</span>
                                            <span>{formatRupiah(grandTotal)}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan PO{' '}
                                    <span className="ml-1 normal-case font-normal text-white/70">
                                        (Ctrl+Enter)
                                    </span>
                                </PrimaryButton>
                                <Link
                                    href={route(
                                        'pembelian.purchase-orders.index',
                                    )}
                                >
                                    <SecondaryButton type="button">
                                        Batal
                                    </SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <Modal
                show={showConfirmSave}
                onClose={() => setShowConfirmSave(false)}
                maxWidth="md"
            >
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">
                        Konfirmasi Simpan PO
                    </h3>
                    <div className="mt-4 space-y-2 text-sm text-gray-700">
                        <div className="flex justify-between">
                            <span>Supplier</span>
                            <span className="font-medium">
                                {supplierItem?.name ?? '-'}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span>Jumlah baris</span>
                            <span className="font-medium">
                                {data.lines.length}
                            </span>
                        </div>
                        <div className="flex justify-between border-t border-gray-200 pt-2 font-semibold text-gray-900">
                            <span>Total PO</span>
                            <span>{formatRupiah(grandTotal)}</span>
                        </div>
                    </div>
                    <div className="mt-4">
                        <InputLabel
                            htmlFor="notes"
                            value="Catatan (opsional)"
                        />
                        <textarea
                            id="notes"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                            rows={3}
                            placeholder='mis. "kurir bilang sisa menyusul minggu depan"'
                            value={data.notes}
                            onChange={(e) =>
                                setData('notes', e.target.value)
                            }
                        />
                        <InputError
                            className="mt-2"
                            message={errors.notes}
                        />
                    </div>
                    <p className="mt-4 text-sm text-gray-600">
                        Setelah disimpan, PO menjadi dokumen resmi. Periksa
                        sekali lagi sebelum melanjutkan.
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton
                            onClick={() => setShowConfirmSave(false)}
                        >
                            Periksa Lagi
                        </SecondaryButton>
                        <PrimaryButton
                            disabled={processing}
                            onClick={confirmSave}
                        >
                            Ya, Simpan PO
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
