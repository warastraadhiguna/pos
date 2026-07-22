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

// `occurred_at` adalah momen transaksi SEBENARNYA (bisa null untuk baris
// lama sebelum kolom ini ada) -- selalu diformat eksplisit ke Asia/Jakarta
// di sini, TIDAK diasumsikan dari string mentahnya, supaya benar apa pun
// offset yang dikirim server. `date` (hari kalender WIB, selalu ada) jadi
// fallback saat `occurred_at` null, dengan penanda supaya jelas jamnya
// tidak tersedia -- bukan disamarkan seolah jam 00:00.
const formatDateTimeWIB = (occurredAt, fallbackDate) => {
    if (!occurredAt) {
        return `${formatDate(fallbackDate)} (jam tidak tercatat)`;
    }
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(occurredAt));
    const get = (type) => parts.find((p) => p.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')} WIB`;
};

const statusLabel = {
    completed: 'Selesai',
    void: 'Batal',
    refunded: 'Refund',
};

const paymentLabel = {
    cash: 'Tunai',
};

export default function Index({ sales, filters, cashiers, summary }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [search, setSearch] = useState(filters.search);
    const [cashierId, setCashierId] = useState(filters.cashier_id ?? '');

    const applyFilters = (overrides = {}) => {
        router.get(
            route('penjualan.index'),
            {
                date_from: dateFrom,
                date_to: dateTo,
                search,
                cashier_id: cashierId,
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitFilters = (e) => {
        e.preventDefault();
        applyFilters();
    };

    const resetToLast7Days = () => {
        const today = new Date().toISOString().slice(0, 10);
        const sevenDaysAgo = new Date(Date.now() - 6 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
        setDateFrom(sevenDaysAgo);
        setDateTo(today);
        setSearch('');
        setCashierId('');
        applyFilters({ date_from: sevenDaysAgo, date_to: today, search: '', cashier_id: '' });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Riwayat Penjualan
                </h2>
            }
        >
            <Head title="Riwayat Penjualan" />

            <div className="py-12">
                <div className="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
                    <form
                        onSubmit={submitFilters}
                        className="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm"
                    >
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
                            <InputLabel htmlFor="cashier_id" value="Kasir" />
                            <SelectInput
                                id="cashier_id"
                                className="mt-1 h-10"
                                value={cashierId}
                                onChange={(e) => {
                                    setCashierId(e.target.value);
                                    applyFilters({ cashier_id: e.target.value });
                                }}
                            >
                                <option value="">Semua Kasir</option>
                                {cashiers.map((cashier) => (
                                    <option key={cashier.id} value={cashier.id}>
                                        {cashier.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>
                        <div className="flex-1 min-w-[200px]">
                            <InputLabel htmlFor="search" value="Cari (No. Transaksi / Produk)" />
                            <TextInput
                                id="search"
                                type="text"
                                className="mt-1 h-10 block w-full"
                                placeholder="mis. #123 atau nama produk"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Aksi" className="invisible" />
                            <div className="mt-1 flex gap-2">
                                <PrimaryButton type="submit" className="h-10">
                                    Cari
                                </PrimaryButton>
                                <SecondaryButton
                                    type="button"
                                    className="h-10"
                                    onClick={resetToLast7Days}
                                >
                                    7 Hari Terakhir
                                </SecondaryButton>
                            </div>
                        </div>
                    </form>

                    <div className="flex flex-wrap gap-4 rounded-lg bg-white p-4 shadow-sm">
                        <div className="text-sm text-gray-600">
                            Total Transaksi:{' '}
                            <span className="font-semibold text-gray-900">{summary.count}</span>
                        </div>
                        <div className="text-sm text-gray-600">
                            Total Nilai:{' '}
                            <span className="font-semibold text-gray-900">{formatRupiah(summary.total)}</span>
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        No. Transaksi
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tanggal &amp; Jam
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Kasir
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Pembayaran
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Total
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {sales.map((sale) => (
                                    <tr key={sale.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            #{sale.id}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDateTimeWIB(sale.occurred_at, sale.date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {sale.created_by_user?.name ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {paymentLabel[sale.payment_method] ?? sale.payment_method}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {statusLabel[sale.status] ?? sale.status}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900">
                                            {formatRupiah(sale.grand_total)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <Link
                                                href={route('penjualan.show', sale.id)}
                                                className="text-primary hover:text-primary-dark"
                                            >
                                                Detail
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {sales.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-6 py-6 text-center text-sm text-gray-500"
                                        >
                                            Tidak ada transaksi penjualan yang cocok dengan filter.
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
