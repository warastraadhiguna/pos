import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import ItemCombobox from '@/Components/ItemCombobox';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import axios from 'axios';
import { useState } from 'react';

export default function QuickItemPicker({
    initialItem = null,
    onSelect,
    uoms,
    error,
    suggestedSku = '',
    suggestedName = '',
}) {
    const [selectedItem, setSelectedItem] = useState(initialItem);
    const [showCreate, setShowCreate] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [form, setForm] = useState({
        sku: '',
        name: '',
        base_uom_id: '',
        standard_cost: '0',
    });

    const setField = (field, fieldValue) => {
        setForm((previous) => ({ ...previous, [field]: fieldValue }));
    };

    const handleSelect = (item) => {
        setSelectedItem(item);
        onSelect(item);
    };

    const openCreate = () => {
        // Kalau Barcode/Nama produk sudah diisi, tawarkan nilai yang sama —
        // untuk produk "jual apa adanya", Item-nya memang literal benda yang
        // sama dengan produknya, jadi SKU & nama yang sama itu wajar, bukan
        // dipaksakan. Tetap bisa diganti; ini cuma prefill.
        setForm((previous) => ({
            ...previous,
            sku: previous.sku || suggestedSku,
            name: previous.name || suggestedName,
        }));
        setShowCreate(true);
    };

    const createItem = async () => {
        setProcessing(true);
        setErrors({});

        try {
            const response = await axios.post(
                route('master.items.quick-create'),
                form,
            );
            handleSelect(response.data);
            setForm({ sku: '', name: '', base_uom_id: '', standard_cost: '0' });
            setShowCreate(false);
        } catch (e) {
            if (e.response?.status === 422) {
                setErrors(e.response.data.errors ?? {});
            } else {
                setErrors({ sku: 'Gagal membuat item. Coba lagi.' });
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <div>
            <InputLabel value="Item" />
            <div className="mt-1 flex gap-2">
                <div className="flex-1">
                    <ItemCombobox
                        key={selectedItem?.id ?? 'empty'}
                        initialItem={selectedItem}
                        onSelect={handleSelect}
                    />
                </div>
                <SecondaryButton
                    type="button"
                    className="whitespace-nowrap"
                    onClick={() => (showCreate ? setShowCreate(false) : openCreate())}
                >
                    + Buat Item Baru
                </SecondaryButton>
            </div>
            <InputError className="mt-2" message={error} />

            {showCreate && (
                <div className="mt-3 space-y-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                    <p className="text-sm font-medium text-gray-700">
                        Buat item baru
                    </p>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel htmlFor="quick-sku" value="SKU" />
                            <TextInput
                                id="quick-sku"
                                className="mt-1 block w-full"
                                value={form.sku}
                                onChange={(e) => setField('sku', e.target.value)}
                            />
                            {suggestedSku && form.sku === suggestedSku && (
                                <p className="mt-1 text-xs text-gray-400">
                                    Disamakan dengan barcode produk — boleh diganti
                                    kalau mau pakai kode internal sendiri.
                                </p>
                            )}
                            <InputError className="mt-1" message={errors.sku} />
                        </div>

                        <div>
                            <InputLabel htmlFor="quick-name" value="Nama" />
                            <TextInput
                                id="quick-name"
                                className="mt-1 block w-full"
                                value={form.name}
                                onChange={(e) => setField('name', e.target.value)}
                            />
                            {suggestedName && form.name === suggestedName && (
                                <p className="mt-1 text-xs text-gray-400">
                                    Disamakan dengan nama produk — boleh diganti
                                    kalau mau pakai nama internal sendiri.
                                </p>
                            )}
                            <InputError className="mt-1" message={errors.name} />
                        </div>

                        <div>
                            <InputLabel htmlFor="quick-uom" value="Satuan Dasar" />
                            <SelectInput
                                id="quick-uom"
                                className="mt-1 block w-full"
                                value={form.base_uom_id}
                                onChange={(e) => setField('base_uom_id', e.target.value)}
                            >
                                <option value="" disabled>
                                    Pilih satuan...
                                </option>
                                {uoms.map((uom) => (
                                    <option key={uom.id} value={uom.id}>
                                        {uom.code}
                                    </option>
                                ))}
                            </SelectInput>
                            <InputError className="mt-1" message={errors.base_uom_id} />
                        </div>

                        <div>
                            <InputLabel htmlFor="quick-cost" value="HPP Awal" />
                            <TextInput
                                id="quick-cost"
                                type="number"
                                step="0.0001"
                                min="0"
                                className="mt-1 block w-full"
                                value={form.standard_cost}
                                onChange={(e) => setField('standard_cost', e.target.value)}
                            />
                            <InputError className="mt-1" message={errors.standard_cost} />
                        </div>
                    </div>

                    <p className="text-xs text-gray-400">
                        Item ini dibuat sebagai "dilacak stok", dengan satuan beli sama
                        dengan satuan dasar. Kalau perlu disesuaikan lebih lanjut,
                        edit di menu Item setelah dibuat.
                    </p>

                    <div className="flex gap-2">
                        <PrimaryButton
                            type="button"
                            disabled={processing}
                            onClick={createItem}
                        >
                            Buat & Pilih
                        </PrimaryButton>
                        <SecondaryButton
                            type="button"
                            onClick={() => setShowCreate(false)}
                        >
                            Batal
                        </SecondaryButton>
                    </div>
                </div>
            )}
        </div>
    );
}
