import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

export default function Index({ assets }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Aset Tetap
                </h2>
            }
        >
            <Head title="Aset Tetap" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex justify-end gap-2">
                        <Link href={route('aset.create')}>
                            <PrimaryButton>Catat Aset Baru</PrimaryButton>
                        </Link>
                        <Link href={route('aset.depreciation.index')}>
                            <SecondaryButton>Proses Penyusutan</SecondaryButton>
                        </Link>
                        <Link href={route('aset.payments.index')}>
                            <SecondaryButton>Pelunasan Hutang Aset</SecondaryButton>
                        </Link>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nama
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Kategori
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tanggal Beli
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Harga Perolehan
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Akumulasi Penyusutan
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nilai Buku
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {assets.map((asset) => (
                                    <tr key={asset.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {asset.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {asset.category ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDate(asset.purchase_date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatRupiah(asset.acquisition_cost)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatRupiah(asset.accumulated_depreciation)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold text-gray-900">
                                            {formatRupiah(asset.book_value)}
                                        </td>
                                    </tr>
                                ))}
                                {assets.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Belum ada aset tetap yang dicatat.
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
