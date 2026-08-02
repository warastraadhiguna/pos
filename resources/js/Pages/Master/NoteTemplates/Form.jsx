import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Form({ noteTemplate }) {
    const editing = noteTemplate !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        text: noteTemplate?.text ?? '',
        is_active: noteTemplate?.is_active ?? true,
    });

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('master.note-templates.update', noteTemplate.id));
        } else {
            post(route('master.note-templates.store'));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    {editing ? 'Edit Template Catatan' : 'Tambah Template Catatan'}
                </h2>
            }
        >
            <Head title={editing ? 'Edit Template Catatan' : 'Tambah Template Catatan'} />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="text" value="Teks Template" />
                                <TextInput
                                    id="text"
                                    className="mt-1 block w-full"
                                    placeholder="mis. Tidak pedas, Gula sedikit, Take away"
                                    value={data.text}
                                    onChange={(e) =>
                                        setData('text', e.target.value)
                                    }
                                    isFocused
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.text}
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
                                <Link href={route('master.note-templates.index')}>
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
