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

const emptyLine = () => ({ item_id: '', qty: '', unit_cost: '' });

const isLineBlank = (line) => !line.item_id && !line.qty && !line.unit_cost;

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');

const toNumber = (value) => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
};

/**
 * "Terima Titipan" -- pola form PERSIS PurchaseOrders/Create.jsx (baris
 * item berulang, F2/Ctrl+Enter, konfirmasi sebelum simpan), TAPI lebih
 * sederhana: tidak ada PO/pajak/ukuran pembelian -- barang konsinyasi
 * diterima langsung dalam satuan dasar item-nya (lihat ItemCombobox, kode
 * satuan sudah tampil di labelnya). Menyimpan ini TIDAK PERNAH membuat
 * jurnal apa pun -- lihat docblock ConsignmentService.
 */
export default function Create({ warehouses }) {
    const { data, setData, post, processing, errors } = useForm({
        supplier_id: '',
        warehouse_id: warehouses[0]?.id ?? '',
        date: new Date().toISOString().slice(0, 10),
        lines: [emptyLine()],
        notes: '',
    });

    const [supplierItem, setSupplierItem] = useState(null);
    const [lineItems, setLineItems] = useState([null]);
    const [showConfirmSave, setShowConfirmSave] = useState(false);
    const formRef = useRef(null);

    const addLine = () => {
        setData('lines', [...data.lines, emptyLine()]);
        setLineItems((previous) => [...previous, null]);
    };

    const removeLineWithConfirmation = (index) => {
        const line = data.lines[index];
        if (!isLineBlank(line) && !confirm('Baris ini sudah terisi. Yakin ingin menghapusnya?')) {
            return;
        }
        setData('lines', data.lines.filter((_, i) => i !== index));
        setLineItems((previous) => previous.filter((_, i) => i !== index));
    };

    const updateLine = (index, field, value) => {
        setData('lines', data.lines.map((line, i) => (i === index ? { ...line, [field]: value } : line)));
    };

    const selectLineItem = (index, item) => {
        setData('lines', data.lines.map((line, i) => (i === index ? { ...line, item_id: item.id } : line)));
        setLineItems((previous) => previous.map((existing, i) => (i === index ? item : existing)));
    };

    const selectSupplier = (supplier) => {
        setData('supplier_id', supplier.id);
        setSupplierItem(supplier);
    };

    const totalValue = data.lines.reduce(
        (sum, line) => sum + toNumber(line.qty) * toNumber(line.unit_cost),
        0,
    );

    const submit = (e) => {
        e.preventDefault();
        setShowConfirmSave(true);
    };

    const confirmSave = () => {
        setShowConfirmSave(false);
        post(route('konsinyasi.receipts.store'));
    };

    const handleFormKeyDown = (e) => {
        if (e.key !== 'Enter' || e.ctrlKey || e.defaultPrevented || e.target.tagName === 'TEXTAREA') return;

        e.preventDefault();

        const focusable = Array.from(
            formRef.current?.querySelectorAll('input, select') ?? [],
        ).filter((el) => !el.disabled);
        const currentIndex = focusable.indexOf(e.target);
        if (currentIndex === -1) return;

        focusable[currentIndex + 1]?.focus();
    };

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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.lines]);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Terima Titipan
                </h2>
            }
        >
            <Head title="Terima Titipan" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <InputLabel htmlFor="supplier_id" value="Supplier (Pemilik Barang)" />
                                    <div className="mt-1">
                                        <SupplierCombobox initialItem={supplierItem} onSelect={selectSupplier} />
                                    </div>
                                    <InputError className="mt-2" message={errors.supplier_id} />
                                </div>

                                <div>
                                    <InputLabel htmlFor="warehouse_id" value="Gudang" />
                                    <SelectInput
                                        id="warehouse_id"
                                        className="mt-1 block w-full"
                                        value={data.warehouse_id}
                                        onChange={(e) => setData('warehouse_id', e.target.value)}
                                        required
                                    >
                                        {warehouses.map((warehouse) => (
                                            <option key={warehouse.id} value={warehouse.id}>
                                                {warehouse.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError className="mt-2" message={errors.warehouse_id} />
                                </div>

                                <div>
                                    <InputLabel htmlFor="date" value="Tanggal" />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        className="mt-1 block w-full"
                                        value={data.date}
                                        onChange={(e) => setData('date', e.target.value)}
                                        required
                                    />
                                    <InputError className="mt-2" message={errors.date} />
                                </div>
                            </div>

                            <div>
                                <div className="flex items-center justify-between">
                                    <InputLabel value="Barang Dititipkan" />
                                    <SecondaryButton type="button" className="h-10" onClick={addLine}>
                                        Tambah Baris{' '}
                                        <span className="ml-1 normal-case font-normal text-gray-400">(F2)</span>
                                    </SecondaryButton>
                                </div>
                                <InputError className="mt-2" message={errors.lines} />
                                <p className="mt-1 text-xs text-gray-400">
                                    Hanya item yang ditandai "Item Konsinyasi" milik supplier terpilih yang bisa
                                    diterima di sini (ditandai di form Item).
                                </p>

                                <div className="mt-3 space-y-3">
                                    {data.lines.map((line, index) => (
                                        <div key={index} className="rounded-md border border-gray-200 p-3">
                                            <div className="flex items-center gap-2">
                                                <div className="flex-1">
                                                    <ItemCombobox
                                                        key={lineItems[index]?.id ?? `empty-${index}`}
                                                        className="h-10"
                                                        initialItem={lineItems[index] ?? null}
                                                        onSelect={(item) => selectLineItem(index, item)}
                                                    />
                                                    <InputError
                                                        className="mt-1"
                                                        message={errors[`lines.${index}.item_id`]}
                                                    />
                                                </div>

                                                <div className="w-28">
                                                    <NumberInput
                                                        placeholder="Qty"
                                                        className="h-10 block w-full"
                                                        value={line.qty}
                                                        onChange={(plain) => updateLine(index, 'qty', plain)}
                                                        required
                                                    />
                                                    <InputError
                                                        className="mt-1"
                                                        message={errors[`lines.${index}.qty`]}
                                                    />
                                                </div>

                                                <div className="w-36">
                                                    <NumberInput
                                                        placeholder="Harga/Satuan"
                                                        className="h-10 block w-full"
                                                        value={line.unit_cost}
                                                        onChange={(plain) => updateLine(index, 'unit_cost', plain)}
                                                        required
                                                    />
                                                    <InputError
                                                        className="mt-1"
                                                        message={errors[`lines.${index}.unit_cost`]}
                                                    />
                                                </div>

                                                <DangerButton
                                                    type="button"
                                                    className="h-10"
                                                    onClick={() => removeLineWithConfirmation(index)}
                                                >
                                                    Hapus
                                                </DangerButton>
                                            </div>
                                            <p className="mt-2 text-xs text-gray-500">
                                                Subtotal:{' '}
                                                <span className="font-medium text-gray-700">
                                                    {formatRupiah(toNumber(line.qty) * toNumber(line.unit_cost))}
                                                </span>
                                            </p>
                                        </div>
                                    ))}
                                </div>

                                <div className="mt-4 flex justify-end">
                                    <div className="flex justify-between gap-4 text-sm font-semibold text-gray-900">
                                        <span>Total Nilai Titipan</span>
                                        <span>{formatRupiah(totalValue)}</span>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan{' '}
                                    <span className="ml-1 normal-case font-normal text-white/70">(Ctrl+Enter)</span>
                                </PrimaryButton>
                                <Link href={route('konsinyasi.receipts.index')}>
                                    <SecondaryButton type="button">Batal</SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <Modal show={showConfirmSave} onClose={() => setShowConfirmSave(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Konfirmasi Terima Titipan</h3>
                    <div className="mt-4 space-y-2 text-sm text-gray-700">
                        <div className="flex justify-between">
                            <span>Supplier</span>
                            <span className="font-medium">{supplierItem?.name ?? '-'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span>Jumlah baris</span>
                            <span className="font-medium">{data.lines.length}</span>
                        </div>
                        <div className="flex justify-between border-t border-gray-200 pt-2 font-semibold text-gray-900">
                            <span>Total Nilai</span>
                            <span>{formatRupiah(totalValue)}</span>
                        </div>
                    </div>
                    <div className="mt-4">
                        <InputLabel htmlFor="notes" value="Catatan (opsional)" />
                        <textarea
                            id="notes"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                            rows={3}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                        <InputError className="mt-2" message={errors.notes} />
                    </div>
                    <p className="mt-4 text-sm text-gray-600">
                        Tidak ada jurnal yang tercatat saat ini -- toko belum memiliki/berutang apa pun untuk
                        barang ini sampai benar-benar terjual.
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setShowConfirmSave(false)}>Periksa Lagi</SecondaryButton>
                        <PrimaryButton disabled={processing} onClick={confirmSave}>
                            Ya, Simpan
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
