import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function Form({ outlet }) {
    const editing = outlet !== null;
    // Cabang yang SAAT INI berstatus pusat tidak boleh dilepas langsung
    // dari sini (lihat BranchService::updateOutlet()) -- pindahkan status
    // pusat lewat cabang LAIN, bukan mencentang-lepas di sini.
    const isCurrentHeadquarters = editing && outlet.is_headquarters;

    const { data, setData, post, put, processing, errors } = useForm({
        name: outlet?.name ?? '',
        code: outlet?.code ?? '',
        address: outlet?.address ?? '',
        phone: outlet?.phone ?? '',
        receipt_footer: outlet?.receipt_footer ?? '',
        is_active: outlet?.is_active ?? true,
        is_headquarters: outlet?.is_headquarters ?? false,
    });

    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('master.outlets.update', outlet.id));
        } else {
            post(route('master.outlets.store'));
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
                    {editing ? 'Edit Cabang' : 'Tambah Cabang'}
                </h2>
            }
        >
            <Head title={editing ? 'Edit Cabang' : 'Tambah Cabang'} />

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
                                <InputLabel htmlFor="name" value="Nama Cabang" />
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
                                <InputLabel htmlFor="code" value="Kode Cabang" />
                                <TextInput
                                    id="code"
                                    className="mt-1 block w-full"
                                    placeholder="mis. SBY, PST"
                                    value={data.code}
                                    onChange={(e) =>
                                        setData('code', e.target.value)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.code}
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="address" value="Alamat" />
                                <TextInput
                                    id="address"
                                    className="mt-1 block w-full"
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

                            <div className="space-y-4 rounded-md border border-gray-200 p-4">
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900">
                                        Identitas Struk
                                    </h3>
                                    <p className="mt-1 text-xs text-gray-500">
                                        {isCurrentHeadquarters ? (
                                            <>
                                                Cabang pusat SELALU pakai Identitas
                                                Toko dari halaman Pengaturan — field
                                                di bawah ini tidak dipakai untuk
                                                struk cabang pusat.
                                            </>
                                        ) : (
                                            <>
                                                Ditampilkan di header struk transaksi
                                                cabang ini (nama pakai "Nama
                                                Cabang"/alamat pakai "Alamat" di
                                                atas). Kosongkan field mana pun
                                                untuk memakai Identitas Toko
                                                (Pengaturan) sebagai gantinya —
                                                cabang baru yang belum diisi tetap
                                                mencetak struk dengan identitas
                                                pusat, tidak pernah kosong.
                                            </>
                                        )}
                                    </p>
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
                                    <InputLabel
                                        htmlFor="receipt_footer"
                                        value="Footer Struk"
                                    />
                                    <textarea
                                        id="receipt_footer"
                                        rows={2}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                                        value={data.receipt_footer}
                                        onChange={(e) =>
                                            setData('receipt_footer', e.target.value)
                                        }
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.receipt_footer}
                                    />
                                </div>
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

                            <div>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="is_headquarters"
                                        checked={data.is_headquarters}
                                        disabled={isCurrentHeadquarters}
                                        onChange={(e) =>
                                            setData(
                                                'is_headquarters',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    <InputLabel
                                        htmlFor="is_headquarters"
                                        value="Cabang Pusat (tempat pembelian masuk)"
                                    />
                                </div>
                                {isCurrentHeadquarters && (
                                    <p className="mt-1 text-xs text-gray-500">
                                        Cabang ini adalah pusat saat ini.
                                        Untuk memindahkan status pusat,
                                        centang "Cabang Pusat" di cabang
                                        lain.
                                    </p>
                                )}
                                <InputError
                                    className="mt-2"
                                    message={errors.is_headquarters}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan
                                </PrimaryButton>
                                <Link href={route('master.outlets.index')}>
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
