import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Form({ role, permissionGroups }) {
    const editing = role !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        name: role?.name ?? '',
        permission_ids: role?.permission_ids ?? [],
    });

    const togglePermission = (id) => {
        setData(
            'permission_ids',
            data.permission_ids.includes(id)
                ? data.permission_ids.filter((existing) => existing !== id)
                : [...data.permission_ids, id],
        );
    };

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('roles.update', role.id));
        } else {
            post(route('roles.store'));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    {editing ? 'Edit Role' : 'Tambah Role'}
                </h2>
            }
        >
            <Head title={editing ? 'Edit Role' : 'Tambah Role'} />

            <div className="py-12">
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="name" value="Nama Role" />
                                <TextInput
                                    id="name"
                                    className="mt-1 block w-full"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    isFocused
                                    required
                                />
                                <InputError className="mt-2" message={errors.name} />
                            </div>

                            <div>
                                <InputLabel value="Izin Akses" />
                                <div className="mt-2 space-y-5">
                                    {Object.entries(permissionGroups).map(([group, permissions]) => (
                                        <div key={group}>
                                            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                {group}
                                            </p>
                                            <div className="space-y-2">
                                                {permissions.map((permission) => (
                                                    <label
                                                        key={permission.id}
                                                        className="flex items-center gap-2 text-sm text-gray-700"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            className="rounded border-gray-300 text-primary shadow-sm focus:ring-primary"
                                                            checked={data.permission_ids.includes(permission.id)}
                                                            onChange={() => togglePermission(permission.id)}
                                                        />
                                                        {permission.label}
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <InputError className="mt-2" message={errors.permission_ids} />
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>Simpan</PrimaryButton>
                                <Link href={route('roles.index')}>
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
