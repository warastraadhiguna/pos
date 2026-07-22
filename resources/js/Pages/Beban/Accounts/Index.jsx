import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const RESERVED_CODES = ['5-1000', '5-2000'];

export default function Index({ accounts }) {
    const { errors } = usePage().props;

    const { data, setData, post, processing, reset } = useForm({
        code: '',
        name: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('beban.accounts.store'), {
            onSuccess: () => reset(),
        });
    };

    const toggleActive = (account) => {
        router.put(route('beban.accounts.toggle-active', account.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Kelola Akun Beban
                </h2>
            }
        >
            <Head title="Kelola Akun Beban" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-4 shadow-sm sm:p-6">
                        <h3 className="mb-4 font-semibold text-gray-900">Tambah Akun Beban</h3>
                        <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                            <div>
                                <InputLabel htmlFor="code" value="Kode" />
                                <TextInput
                                    id="code"
                                    type="text"
                                    className="mt-1 h-10 block"
                                    placeholder="mis. 5-3300"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    required
                                />
                                <InputError className="mt-2" message={errors.code} />
                            </div>
                            <div className="flex-1 min-w-[200px]">
                                <InputLabel htmlFor="name" value="Nama Akun" />
                                <TextInput
                                    id="name"
                                    type="text"
                                    className="mt-1 h-10 block w-full"
                                    placeholder="mis. Beban Internet"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                <InputError className="mt-2" message={errors.name} />
                            </div>
                            <PrimaryButton disabled={processing} className="h-10">
                                Tambah
                            </PrimaryButton>
                        </form>
                        <p className="mt-3 text-xs text-gray-400">
                            Kode harus berawalan "5-3" (rentang beban operasional). Akun yang sudah
                            dipakai untuk mencatat beban tidak bisa dihapus -- hanya bisa
                            dinonaktifkan.
                        </p>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Kode
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nama
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {accounts.map((account) => {
                                    const reserved = RESERVED_CODES.includes(account.code);

                                    return (
                                        <tr key={account.id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {account.code}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {account.name}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm">
                                                {account.is_active ? (
                                                    <span className="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">
                                                        Aktif
                                                    </span>
                                                ) : (
                                                    <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">
                                                        Nonaktif
                                                    </span>
                                                )}
                                                {reserved && (
                                                    <span className="ml-2 text-xs text-gray-400">
                                                        (sistem)
                                                    </span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                                {!reserved && (
                                                    <SecondaryButton onClick={() => toggleActive(account)}>
                                                        {account.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                                    </SecondaryButton>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {accounts.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Belum ada akun beban.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
