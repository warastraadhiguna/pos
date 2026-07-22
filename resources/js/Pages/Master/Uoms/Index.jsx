import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ uoms }) {
    const destroy = (uom) => {
        if (confirm(`Hapus ukuran "${uom.name}"?`)) {
            router.delete(route('master.uoms.destroy', uom.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Ukuran
                </h2>
            }
        >
            <Head title="Ukuran" />

            <div className="py-12">
                <div className="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex justify-end">
                        <Link href={route('master.uoms.create')}>
                            <PrimaryButton>Tambah Ukuran</PrimaryButton>
                        </Link>
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
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {uoms.map((uom) => (
                                    <tr key={uom.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {uom.code}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {uom.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <Link
                                                href={route(
                                                    'master.uoms.edit',
                                                    uom.id,
                                                )}
                                                className="text-primary hover:text-primary-dark"
                                            >
                                                Edit
                                            </Link>
                                            <DangerButton
                                                className="ms-4"
                                                onClick={() => destroy(uom)}
                                            >
                                                Hapus
                                            </DangerButton>
                                        </td>
                                    </tr>
                                ))}
                                {uoms.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="px-6 py-4 text-center text-sm text-gray-500"
                                        >
                                            Belum ada ukuran.
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
