import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const formatQty = (value) => {
    if (value === null || value === undefined) return '—';
    return Number(value).toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 4,
    });
};

const formatRupiah = (value) => {
    if (value === null || value === undefined) return '—';
    return 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
};

const costingLabel = {
    stocked: 'Dilacak stok',
    cost_only: 'Cost only',
};

export default function InventoryList({ items, warehouseId, warehouses }) {
    const [sortDir, setSortDir] = useState(null); // null | 'asc' | 'desc'

    const sortedItems = useMemo(() => {
        if (!sortDir) return items;

        // Item cost_only tidak punya angka stok sungguhan ("—"), jadi selalu
        // ditaruh di bawah terlepas arah urutan — tidak masuk akal diurutkan
        // bersama angka stok yang nyata.
        const tracked = items.filter((item) => item.stock !== null);
        const untracked = items.filter((item) => item.stock === null);

        tracked.sort((a, b) => {
            const diff = Number(a.stock) - Number(b.stock);
            return sortDir === 'asc' ? diff : -diff;
        });

        return [...tracked, ...untracked];
    }, [items, sortDir]);

    const toggleSort = () => {
        setSortDir((previous) =>
            previous === 'asc' ? 'desc' : previous === 'desc' ? null : 'asc',
        );
    };

    const changeWarehouse = (e) => {
        router.get(
            route('laporan.stok'),
            { warehouse_id: e.target.value },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Daftar Stok
                </h2>
            }
        >
            <Head title="Daftar Stok" />

            <div className="py-12">
                <div className="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                        <p className="text-sm text-gray-500">
                            Stok & HPP rata-rata terkini tiap item. Item bertanda merah
                            berarti stoknya 0 atau minus — perlu dibeli.
                        </p>
                        <div className="flex items-center gap-2">
                            <label className="text-sm text-gray-600">Gudang</label>
                            <select
                                value={warehouseId}
                                onChange={changeWarehouse}
                                className="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
                            >
                                {warehouses.map((warehouse) => (
                                    <option key={warehouse.id} value={warehouse.id}>
                                        {warehouse.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        SKU
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nama Item
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Kategori
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tipe
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Satuan
                                    </th>
                                    <th
                                        onClick={toggleSort}
                                        className="cursor-pointer select-none px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700"
                                    >
                                        Stok
                                        {sortDir === 'asc' && ' ↑'}
                                        {sortDir === 'desc' && ' ↓'}
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        HPP Rata-rata
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nilai Persediaan
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {sortedItems.map((item) => {
                                    const isLow = item.stock !== null && Number(item.stock) <= 0;

                                    return (
                                        <tr key={item.id} className={isLow ? 'bg-red-50' : ''}>
                                            <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-900">
                                                {item.sku}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                {item.name}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {item.category ?? '-'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {costingLabel[item.costing_type]}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {item.uom ?? '-'}
                                            </td>
                                            <td
                                                className={
                                                    'whitespace-nowrap px-6 py-4 text-right text-sm ' +
                                                    (isLow
                                                        ? 'font-semibold text-red-700'
                                                        : 'text-gray-900')
                                                }
                                            >
                                                {formatQty(item.stock)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                                {formatRupiah(item.average_cost)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-900">
                                                {formatRupiah(item.inventory_value)}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {sortedItems.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-6 py-6 text-center text-sm text-gray-500"
                                        >
                                            Belum ada item.
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
