import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import ItemCombobox from '@/Components/ItemCombobox';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

const emptyLine = () => ({ item_id: '', qty: '' });
const isLineBlank = (line) => !line.item_id && !line.qty;

export default function Create({ sourceWarehouses, destWarehouses }) {
    const { data, setData, post, processing, errors } = useForm({
        source_warehouse_id: sourceWarehouses[0]?.id ?? '',
        dest_warehouse_id: destWarehouses[0]?.id ?? '',
        date: new Date().toISOString().slice(0, 10),
        lines: [emptyLine()],
        notes: '',
    });

    // Katalog item lengkap TIDAK dimuat penuh -- ItemCombobox mencari ke
    // server per ketikan, pola sama Pembelian/PurchaseOrders/Create.jsx.
    // lineItems selaras indeksnya dengan data.lines.
    const [lineItems, setLineItems] = useState([null]);
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
        updateLine(index, 'item_id', item.id);
        setLineItems((previous) => previous.map((existing, i) => (i === index ? item : existing)));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('distribusi.stock-distributions.store'));
    };

    // Enter pindah ke field berikutnya, bukan submit tak sengaja -- pola
    // sama form master/pembelian lain.
    const handleFormKeyDown = (e) => {
        if (e.key !== 'Enter' || e.ctrlKey || e.defaultPrevented || e.target.tagName === 'TEXTAREA') return;
        e.preventDefault();
        const focusable = Array.from(formRef.current?.querySelectorAll('input, select') ?? []).filter((el) => !el.disabled);
        const currentIndex = focusable.indexOf(e.target);
        if (currentIndex === -1) return;
        focusable[currentIndex + 1]?.focus();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Buat Distribusi Stok
                </h2>
            }
        >
            <Head title="Buat Distribusi Stok" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        {sourceWarehouses.length === 0 && (
                            <p className="mb-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">
                                Belum ada gudang pusat yang aktif. Tandai satu cabang sebagai "Cabang Pusat" dulu di halaman Kelola Cabang.
                            </p>
                        )}
                        {destWarehouses.length === 0 && (
                            <p className="mb-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">
                                Belum ada gudang cabang tujuan. Tambah cabang lain dulu di halaman Kelola Cabang.
                            </p>
                        )}
                        <form ref={formRef} onSubmit={submit} onKeyDown={handleFormKeyDown} className="space-y-6">
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <InputLabel htmlFor="source_warehouse_id" value="Dari Gudang (Pusat)" />
                                    <SelectInput
                                        id="source_warehouse_id"
                                        className="mt-1 block w-full"
                                        value={data.source_warehouse_id}
                                        onChange={(e) => setData('source_warehouse_id', e.target.value)}
                                        required
                                    >
                                        {sourceWarehouses.map((warehouse) => (
                                            <option key={warehouse.id} value={warehouse.id}>
                                                {warehouse.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError className="mt-2" message={errors.source_warehouse_id} />
                                </div>

                                <div>
                                    <InputLabel htmlFor="dest_warehouse_id" value="Ke Gudang (Cabang)" />
                                    <SelectInput
                                        id="dest_warehouse_id"
                                        className="mt-1 block w-full"
                                        value={data.dest_warehouse_id}
                                        onChange={(e) => setData('dest_warehouse_id', e.target.value)}
                                        required
                                    >
                                        {destWarehouses.map((warehouse) => (
                                            <option key={warehouse.id} value={warehouse.id}>
                                                {warehouse.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError className="mt-2" message={errors.dest_warehouse_id} />
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
                                    <InputLabel value="Item yang Didistribusikan" />
                                    <SecondaryButton type="button" className="h-10" onClick={addLine}>
                                        Tambah Baris
                                    </SecondaryButton>
                                </div>
                                <InputError className="mt-2" message={errors.lines} />

                                <div className="mt-3 space-y-3">
                                    {data.lines.map((line, index) => (
                                        <div key={index} className="flex items-center gap-2 rounded-md border border-gray-200 p-3">
                                            <div className="flex-1">
                                                <ItemCombobox
                                                    key={lineItems[index]?.id ?? `empty-${index}`}
                                                    className="h-10"
                                                    initialItem={lineItems[index] ?? null}
                                                    onSelect={(item) => selectLineItem(index, item)}
                                                />
                                                <InputError className="mt-1" message={errors[`lines.${index}.item_id`]} />
                                            </div>

                                            <div className="w-32">
                                                <NumberInput
                                                    placeholder="Qty"
                                                    className="h-10 block w-full"
                                                    value={line.qty}
                                                    onChange={(plain) => updateLine(index, 'qty', plain)}
                                                    required
                                                />
                                                <InputError className="mt-1" message={errors[`lines.${index}.qty`]} />
                                            </div>

                                            <DangerButton type="button" className="h-10" onClick={() => removeLineWithConfirmation(index)}>
                                                Hapus
                                            </DangerButton>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <InputLabel htmlFor="notes" value="Catatan (opsional)" />
                                <textarea
                                    id="notes"
                                    rows={2}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                                <InputError className="mt-2" message={errors.notes} />
                            </div>

                            <p className="text-xs text-gray-400">
                                HPP belum ditentukan di sini -- dihitung otomatis dari HPP gudang pusat SAAT distribusi ini dieksekusi (lihat halaman detail setelah disimpan).
                            </p>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>Simpan Distribusi</PrimaryButton>
                                <Link href={route('distribusi.stock-distributions.index')}>
                                    <SecondaryButton type="button">Batal</SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
