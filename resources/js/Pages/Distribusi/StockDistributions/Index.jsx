import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const statusBadge = {
    draft: (
        <span className="rounded-full bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800">
            Draft
        </span>
    ),
    completed: (
        <span className="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">
            Selesai
        </span>
    ),
    cancelled: (
        <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">
            Dibatalkan
        </span>
    ),
};

export default function Index({ distributions }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Distribusi Stok
                </h2>
            }
        >
            <Head title="Distribusi Stok" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex justify-end">
                        <Link href={route('distribusi.stock-distributions.create')}>
                            <PrimaryButton>Buat Distribusi</PrimaryButton>
                        </Link>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tanggal
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Dari
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Ke
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Jumlah Item
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {distributions.map((distribution) => (
                                    <tr key={distribution.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {distribution.date}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {distribution.source_warehouse?.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {distribution.dest_warehouse?.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {distribution.lines_count}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            {statusBadge[distribution.status] ?? distribution.status}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <Link
                                                href={route('distribusi.stock-distributions.show', distribution.id)}
                                                className="text-primary hover:text-primary-dark"
                                            >
                                                Detail
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {distributions.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Belum ada distribusi.
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
