import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function Form({ item, uoms, accounts, itemCategories }) {
    const editing = item !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        sku: item?.sku ?? '',
        name: item?.name ?? '',
        costing_type: item?.costing_type ?? 'stocked',
        base_uom_id: item?.base_uom_id ?? '',
        purchase_uom_id: item?.purchase_uom_id ?? '',
        standard_cost: item?.standard_cost ?? '0',
        inventory_account_id: item?.inventory_account_id ?? '',
        item_category_id: item?.item_category_id ?? '',
        is_active: item?.is_active ?? true,
    });

    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('master.items.update', item.id));
        } else {
            post(route('master.items.store'));
        }
    };

    // Enter pindah ke field berikutnya, bukan submit tak sengaja — pola sama
    // dengan form Pembelian.
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
                    {editing ? 'Edit Item' : 'Tambah Item'}
                </h2>
            }
        >
            <Head title={editing ? 'Edit Item' : 'Tambah Item'} />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div>
                                <InputLabel htmlFor="sku" value="SKU" />
                                <TextInput
                                    id="sku"
                                    className="mt-1 block w-full"
                                    value={data.sku}
                                    onChange={(e) =>
                                        setData('sku', e.target.value)
                                    }
                                    isFocused
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.sku}
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="name" value="Nama" />
                                <TextInput
                                    id="name"
                                    className="mt-1 block w-full"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="costing_type"
                                    value="Tipe Costing"
                                />
                                <SelectInput
                                    id="costing_type"
                                    className="mt-1 block w-full"
                                    value={data.costing_type}
                                    onChange={(e) =>
                                        setData(
                                            'costing_type',
                                            e.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="stocked">
                                        Dilacak stok (stocked)
                                    </option>
                                    <option value="cost_only">
                                        Cost only (tanpa stok, mis. air)
                                    </option>
                                </SelectInput>
                                <InputError
                                    className="mt-2"
                                    message={errors.costing_type}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel
                                        htmlFor="base_uom_id"
                                        value="Ukuran Dasar"
                                    />
                                    <SelectInput
                                        id="base_uom_id"
                                        className="mt-1 block w-full"
                                        value={data.base_uom_id}
                                        onChange={(e) =>
                                            setData(
                                                'base_uom_id',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    >
                                        <option value="" disabled>
                                            Pilih Ukuran
                                        </option>
                                        {uoms.map((uom) => (
                                            <option
                                                key={uom.id}
                                                value={uom.id}
                                            >
                                                {uom.code} — {uom.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError
                                        className="mt-2"
                                        message={errors.base_uom_id}
                                    />
                                </div>

                                <div>
                                    <InputLabel
                                        htmlFor="purchase_uom_id"
                                        value="Ukuran Pembelian"
                                    />
                                    <SelectInput
                                        id="purchase_uom_id"
                                        className="mt-1 block w-full"
                                        value={data.purchase_uom_id}
                                        onChange={(e) =>
                                            setData(
                                                'purchase_uom_id',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    >
                                        <option value="" disabled>
                                            Pilih Ukuran
                                        </option>
                                        {uoms.map((uom) => (
                                            <option
                                                key={uom.id}
                                                value={uom.id}
                                            >
                                                {uom.code} — {uom.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError
                                        className="mt-2"
                                        message={errors.purchase_uom_id}
                                    />
                                </div>
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="standard_cost"
                                    value="Standard Cost (dipakai untuk item cost_only)"
                                />
                                <NumberInput
                                    id="standard_cost"
                                    maxDecimals={4}
                                    className="mt-1 block w-full"
                                    value={data.standard_cost}
                                    onChange={(plain) =>
                                        setData('standard_cost', plain)
                                    }
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.standard_cost}
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="inventory_account_id"
                                    value="Akun Persediaan"
                                />
                                <SelectInput
                                    id="inventory_account_id"
                                    className="mt-1 block w-full"
                                    value={data.inventory_account_id}
                                    onChange={(e) =>
                                        setData(
                                            'inventory_account_id',
                                            e.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="" disabled>
                                        Pilih akun
                                    </option>
                                    {accounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.code} — {account.name}
                                        </option>
                                    ))}
                                </SelectInput>
                                <InputError
                                    className="mt-2"
                                    message={errors.inventory_account_id}
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="item_category_id"
                                    value="Kategori Item"
                                />
                                <SelectInput
                                    id="item_category_id"
                                    className="mt-1 block w-full"
                                    value={data.item_category_id}
                                    onChange={(e) =>
                                        setData(
                                            'item_category_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Tanpa kategori</option>
                                    {itemCategories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                        </option>
                                    ))}
                                </SelectInput>
                                <InputError
                                    className="mt-2"
                                    message={errors.item_category_id}
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_active"
                                    checked={data.is_active}
                                    onChange={(e) =>
                                        setData(
                                            'is_active',
                                            e.target.checked,
                                        )
                                    }
                                />
                                <InputLabel
                                    htmlFor="is_active"
                                    value="Aktif"
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan
                                </PrimaryButton>
                                <Link href={route('master.items.index')}>
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
