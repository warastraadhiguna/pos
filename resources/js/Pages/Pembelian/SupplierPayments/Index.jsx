import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

export default function Index({ payments, filters }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (overrides = {}) => {
        router.get(
            route('pembelian.supplier-payments.index'),
            {
                date_from: dateFrom,
                date_to: dateTo,
                search,
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitFilters = (e) => {
        e.preventDefault();
        applyFilters();
    };

    const resetToToday = () => {
        const today = new Date().toISOString().slice(0, 10);
        setDateFrom(today);
        setDateTo(today);
        setSearch('');
        applyFilters({ date_from: today, date_to: today, search: '' });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Pembayaran Hutang Supplier
                </h2>
            }
        >
            <Head title="Pembayaran Hutang Supplier" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex justify-end">
                        <Link href={route('pembelian.supplier-payments.create')}>
                            <PrimaryButton>Bayar Hutang</PrimaryButton>
                        </Link>
                    </div>

                    <form
                        onSubmit={submitFilters}
                        className="flex flex-wrap items-center gap-4 rounded-lg bg-white p-4 shadow-sm"
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
                        <div className="flex-1 min-w-[200px]">
                            <InputLabel htmlFor="search" value="Cari (Supplier / Catatan)" />
                            <TextInput
                                id="search"
                                type="text"
                                className="mt-1 h-10 block w-full"
                                placeholder="mis. nama supplier atau catatan"
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
                                    onClick={resetToToday}
                                >
                                    Hari Ini
                                </SecondaryButton>
                            </div>
                        </div>
                    </form>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tanggal
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Supplier
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Dialokasikan Ke
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
                                {payments.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDate(payment.date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {payment.supplier?.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {(() => {
                                                const notaAllocations =
                                                    payment.allocations.filter(
                                                        (a) =>
                                                            a.goods_receipt_id !==
                                                            null,
                                                    );
                                                const hasAdvance =
                                                    payment.allocations.some(
                                                        (a) =>
                                                            a.goods_receipt_id ===
                                                            null,
                                                    );
                                                const parts = [];
                                                if (
                                                    notaAllocations.length > 0
                                                ) {
                                                    parts.push(
                                                        notaAllocations
                                                            .map(
                                                                (a) =>
                                                                    `Nota #${a.goods_receipt_id}`,
                                                            )
                                                            .join(', '),
                                                    );
                                                }
                                                if (hasAdvance) {
                                                    parts.push('Uang muka');
                                                }
                                                return parts.length > 0
                                                    ? parts.join(' + ')
                                                    : '-';
                                            })()}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900">
                                            {formatRupiah(payment.amount)}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-600">
                                            {payment.memo ?? '-'}
                                        </td>
                                    </tr>
                                ))}
                                {payments.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-6 py-6 text-center text-sm text-gray-500"
                                        >
                                            Tidak ada pembayaran hutang yang cocok dengan filter.
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
