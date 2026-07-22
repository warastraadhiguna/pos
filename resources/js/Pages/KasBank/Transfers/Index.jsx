import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

export default function Index({ transfers, filters }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);

    const applyFilters = (overrides = {}) => {
        router.get(
            route('kas-bank.transfers.index'),
            { date_from: dateFrom, date_to: dateTo, ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const resetToToday = () => {
        const today = new Date().toISOString().slice(0, 10);
        setDateFrom(today);
        setDateTo(today);
        applyFilters({ date_from: today, date_to: today });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Transfer Kas & Bank
                </h2>
            }
        >
            <Head title="Transfer Kas & Bank" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex justify-end">
                        <Link href={route('kas-bank.transfers.create')}>
                            <PrimaryButton>Transfer Baru</PrimaryButton>
                        </Link>
                    </div>

                    <div className="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm">
                        <div>
                            <InputLabel htmlFor="date_from" value="Dari Tanggal" />
                            <TextInput
                                id="date_from"
                                type="date"
                                className="mt-1 h-10"
                                value={dateFrom}
                                onChange={(e) => {
                                    setDateFrom(e.target.value);
                                    applyFilters({ date_from: e.target.value });
                                }}
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="date_to" value="Sampai Tanggal" />
                            <TextInput
                                id="date_to"
                                type="date"
                                className="mt-1 h-10"
                                value={dateTo}
                                onChange={(e) => {
                                    setDateTo(e.target.value);
                                    applyFilters({ date_to: e.target.value });
                                }}
                            />
                        </div>
                        <SecondaryButton type="button" className="h-10" onClick={resetToToday}>
                            Hari Ini
                        </SecondaryButton>
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
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Jumlah
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Catatan
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {transfers.map((transfer) => (
                                    <tr key={transfer.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDate(transfer.date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {transfer.from_account_code}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {transfer.to_account_code}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900">
                                            {formatRupiah(transfer.amount)}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-600">
                                            {transfer.memo ?? '-'}
                                        </td>
                                    </tr>
                                ))}
                                {transfers.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Tidak ada transfer yang cocok dengan filter.
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
