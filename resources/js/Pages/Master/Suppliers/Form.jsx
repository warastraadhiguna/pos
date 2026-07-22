import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function Form({ supplier }) {
    const editing = supplier !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        name: supplier?.name ?? '',
        phone: supplier?.phone ?? '',
        address: supplier?.address ?? '',
    });

    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('master.suppliers.update', supplier.id));
        } else {
            post(route('master.suppliers.store'));
        }
    };

    // Enter pindah ke field berikutnya, bukan submit tak sengaja — pola sama
    // dengan form Pembelian. Textarea dikecualikan: Enter di situ harus
    // tetap menyisipkan baris baru (alamat multi-baris), bukan dipindah
    // paksa ke field lain.
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

        focusable[currentIndex + 1]?.focus();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    {editing ? 'Edit Supplier' : 'Tambah Supplier'}
                </h2>
            }
        >
            <Head title={editing ? 'Edit Supplier' : 'Tambah Supplier'} />

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
                                <InputLabel htmlFor="name" value="Nama" />
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
                                <InputLabel htmlFor="phone" value="Telepon" />
                                <TextInput
                                    id="phone"
                                    className="mt-1 block w-full"
                                    value={data.phone}
                                    onChange={(e) =>
                                        setData('phone', e.target.value)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.phone}
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="address" value="Alamat" />
                                <textarea
                                    id="address"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                                    rows={3}
                                    value={data.address}
                                    onChange={(e) =>
                                        setData('address', e.target.value)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.address}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan
                                </PrimaryButton>
                                <Link href={route('master.suppliers.index')}>
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
