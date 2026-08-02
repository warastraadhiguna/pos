import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function Form({ table }) {
    const editing = table !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        name: table?.name ?? '',
        capacity: table?.capacity ?? '',
        area: table?.area ?? '',
        is_active: table?.is_active ?? true,
    });

    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('master.tables.update', table.id));
        } else {
            post(route('master.tables.store'));
        }
    };

    // Enter pindah ke field berikutnya, bukan submit tak sengaja — pola sama
    // dengan form master lain.
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
                    {editing ? 'Edit Meja' : 'Tambah Meja'}
                </h2>
            }
        >
            <Head title={editing ? 'Edit Meja' : 'Tambah Meja'} />

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
                                <InputLabel htmlFor="name" value="Nama/Nomor Meja" />
                                <TextInput
                                    id="name"
                                    className="mt-1 block w-full"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    isFocused
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="capacity" value="Kapasitas" />
                                <TextInput
                                    id="capacity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    className="mt-1 block w-full"
                                    value={data.capacity}
                                    onChange={(e) =>
                                        setData('capacity', e.target.value)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.capacity}
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="area" value="Area" />
                                <TextInput
                                    id="area"
                                    className="mt-1 block w-full"
                                    placeholder="mis. indoor, outdoor"
                                    value={data.area}
                                    onChange={(e) =>
                                        setData('area', e.target.value)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.area}
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
                                <Link href={route('master.tables.index')}>
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
