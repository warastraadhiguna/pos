import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

const statusLabel = {
    open: 'Open',
    partial: 'Sebagian Diterima',
    received: 'Diterima Penuh',
    cancelled: 'Dibatalkan',
};

const statusClass = {
    open: 'text-gray-600',
    partial: 'text-amber-600',
    received: 'text-green-700',
    cancelled: 'text-red-600',
};

const notaBadgeLabel = {
    tunai: 'Tunai',
    lunas: 'Kredit-Lunas',
    sebagian: 'Kredit-Sebagian',
    belum: 'Kredit-Belum',
};

const notaBadgeClass = {
    tunai: 'bg-gray-100 text-gray-700',
    lunas: 'bg-green-100 text-green-700',
    sebagian: 'bg-amber-100 text-amber-700',
    belum: 'bg-red-100 text-red-700',
};

export default function Index({ purchaseOrders, receiptBadgesByPo, filters }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (overrides = {}) => {
        router.get(
            route('pembelian.purchase-orders.index'),
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
                    Purchase Order
                </h2>
            }
        >
            <Head title="Purchase Order" />

            <div className="py-12">
                <div className="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex justify-end">
                        <Link
                            href={route(
                                'pembelian.purchase-orders.create',
                            )}
                        >
                            <PrimaryButton>Buat PO</PrimaryButton>
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
                            <InputLabel htmlFor="search" value="Cari (No. PO / Supplier)" />
                            <TextInput
                                id="search"
                                type="text"
                                className="mt-1 h-10 block w-full"
                                placeholder="mis. PO-12 atau nama supplier"
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
                                        PO
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Supplier
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tanggal
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Total
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nota
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {purchaseOrders.map((po) => (
                                    <tr key={po.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            PO-{po.id}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {po.supplier?.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDate(po.date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatRupiah(po.grand_total)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            <span
                                                className={
                                                    statusClass[po.status]
                                                }
                                            >
                                                {statusLabel[po.status]}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            <div className="flex flex-wrap gap-1">
                                                {(
                                                    receiptBadgesByPo[
                                                        po.id
                                                    ] ?? []
                                                ).map((badge) => (
                                                    <span
                                                        key={
                                                            badge.goods_receipt_id
                                                        }
                                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${notaBadgeClass[badge.status]}`}
                                                    >
                                                        {
                                                            notaBadgeLabel[
                                                                badge.status
                                                            ]
                                                        }
                                                    </span>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <Link
                                                href={route(
                                                    'pembelian.purchase-orders.show',
                                                    po.id,
                                                )}
                                                className="text-primary hover:text-primary-dark"
                                            >
                                                Detail
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {purchaseOrders.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-6 py-4 text-center text-sm text-gray-500"
                                        >
                                            Tidak ada purchase order yang cocok dengan filter.
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
