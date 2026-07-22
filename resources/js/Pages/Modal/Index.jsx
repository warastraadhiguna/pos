import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

const typeLabel = {
    modal: { text: 'Setoran Modal', className: 'bg-green-100 text-green-700' },
    prive: { text: 'Prive', className: 'bg-amber-100 text-amber-700' },
};

export default function Index({ transactions, filters }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [type, setType] = useState(filters.type);

    const applyFilters = (overrides = {}) => {
        router.get(
            route('modal.index'),
            { date_from: dateFrom, date_to: dateTo, type, ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const resetToThisMonth = () => {
        const today = new Date().toISOString().slice(0, 10);
        const startOfMonth = today.slice(0, 8) + '01';
        setDateFrom(startOfMonth);
        setDateTo(today);
        setType('');
        applyFilters({ date_from: startOfMonth, date_to: today, type: '' });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Modal & Prive
                </h2>
            }
        >
            <Head title="Modal & Prive" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex justify-end gap-2">
                        <Link href={route('modal.deposit.create')}>
                            <PrimaryButton>Catat Setoran Modal</PrimaryButton>
                        </Link>
                        <Link href={route('modal.withdrawal.create')}>
                            <SecondaryButton>Catat Prive</SecondaryButton>
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
                        <div>
                            <InputLabel htmlFor="type" value="Tipe" />
                            <SelectInput
                                id="type"
                                className="mt-1 h-10"
                                value={type}
                                onChange={(e) => {
                                    setType(e.target.value);
                                    applyFilters({ type: e.target.value });
                                }}
                            >
                                <option value="">Semua</option>
                                <option value="modal">Setoran Modal</option>
                                <option value="prive">Prive</option>
                            </SelectInput>
                        </div>
                        <SecondaryButton type="button" className="h-10" onClick={resetToThisMonth}>
                            Bulan Ini
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
                                        Tipe
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Akun Kas/Bank
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Jumlah
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Keterangan
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {transactions.map((transaction) => (
                                    <tr key={transaction.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDate(transaction.date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            <span
                                                className={`rounded-full px-2 py-1 text-xs font-medium ${typeLabel[transaction.type]?.className}`}
                                            >
                                                {typeLabel[transaction.type]?.text}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {transaction.cash_account_code}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900">
                                            {formatRupiah(transaction.amount)}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-600">
                                            {transaction.description ?? '-'}
                                        </td>
                                    </tr>
                                ))}
                                {transactions.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Tidak ada transaksi modal/prive yang cocok dengan filter.
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
