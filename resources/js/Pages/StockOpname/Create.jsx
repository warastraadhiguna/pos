import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

export default function Create({ warehouses, items }) {
    const [search, setSearch] = useState('');

    const { data, setData, post, processing, errors } = useForm({
        warehouse_id: warehouses[0]?.id ?? '',
        date: new Date().toISOString().slice(0, 10),
        item_ids: [],
    });

    const formRef = useRef(null);

    const filteredItems = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return items;
        return items.filter(
            (item) =>
                item.name.toLowerCase().includes(term) ||
                item.sku.toLowerCase().includes(term),
        );
    }, [items, search]);

    const toggleItem = (itemId) => {
        setData(
            'item_ids',
            data.item_ids.includes(itemId)
                ? data.item_ids.filter((id) => id !== itemId)
                : [...data.item_ids, itemId],
        );
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('stock-opname.store'));
    };

    // Enter di field pencarian/gudang/tanggal TIDAK boleh langsung memulai
    // opname secara tidak sengaja — pola sama dengan form Pembelian: Enter
    // pindah ke field berikutnya, bukan submit.
    const handleFormKeyDown = (e) => {
        if (e.key !== 'Enter' || e.ctrlKey || e.defaultPrevented) return;

        e.preventDefault();

        const focusable = Array.from(
            formRef.current?.querySelectorAll('input, select') ?? [],
        ).filter((el) => !el.disabled);
        const currentIndex = focusable.indexOf(e.target);
        if (currentIndex === -1) return;

        focusable[currentIndex + 1]?.focus();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Buat Stock Opname
                </h2>
            }
        >
            <Head title="Buat Stock Opname" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div className="grid grid-cols-2 gap-4">
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
                                    <InputLabel value="Pilih Item yang Dihitung" />
                                    <span className="text-sm text-gray-500">
                                        {data.item_ids.length} dipilih
                                    </span>
                                </div>

                                <TextInput
                                    placeholder="Cari item..."
                                    className="mt-2 block w-full"
                                    value={search}
                                    onChange={(e) =>
                                        setSearch(e.target.value)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={
                                        errors.item_ids ||
                                        errors['item_ids.0']
                                    }
                                />

                                <div className="mt-2 max-h-80 space-y-1 overflow-y-auto rounded-md border border-gray-200 p-2">
                                    {filteredItems.map((item) => (
                                        <label
                                            key={item.id}
                                            className="flex items-center gap-2 rounded p-1 text-sm hover:bg-gray-50"
                                        >
                                            <Checkbox
                                                checked={data.item_ids.includes(
                                                    item.id,
                                                )}
                                                onChange={() =>
                                                    toggleItem(item.id)
                                                }
                                            />
                                            <span>
                                                {item.sku} — {item.name}
                                            </span>
                                        </label>
                                    ))}
                                    {filteredItems.length === 0 && (
                                        <p className="p-2 text-sm text-gray-500">
                                            Tidak ada item ditemukan.
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton
                                    disabled={
                                        processing ||
                                        data.item_ids.length === 0
                                    }
                                >
                                    Mulai Opname
                                </PrimaryButton>
                                <Link href={route('stock-opname.index')}>
                                    <SecondaryButton type="button">
                                        Batal
                                    </SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
