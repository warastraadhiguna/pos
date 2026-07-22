import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

const formatQty = (value) => Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });

const formatMargin = (value) => (value === null ? '—' : `${Number(value).toFixed(1)}%`);

export default function ProductProfitReport({ start, end, sort, report }) {
    const changeRange = (field, value) => {
        router.get(
            route('laporan.laba-produk'),
            { start: field === 'start' ? value : start, end: field === 'end' ? value : end, sort },
            { preserveState: true, preserveScroll: true },
        );
    };

    const changeSort = (nextSort) => {
        router.get(
            route('laporan.laba-produk'),
            { start, end, sort: nextSort },
            { preserveState: true, preserveScroll: true },
        );
    };

    const totalGrossProfitClass =
        Number(report.totals.gross_profit) >= 0 ? 'text-green-700' : 'text-red-600';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Laba per Produk
                </h2>
            }
        >
            <Head title="Laba per Produk" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                        <div className="flex gap-4 text-sm">
                            <Link
                                href={route('laporan.neraca')}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                Neraca
                            </Link>
                            <Link
                                href={route('laporan.laba-rugi')}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                Laba Rugi
                            </Link>
                            <Link
                                href={route('laporan.penjualan')}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                Penjualan
                            </Link>
                            <Link
                                href={route('laporan.laba-produk')}
                                className="font-semibold text-primary"
                            >
                                Laba per Produk
                            </Link>
                            <Link
                                href={route('laporan.ppn')}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                PPN
                            </Link>
                            <Link
                                href={route('laporan.hutang')}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                Hutang Supplier
                            </Link>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-sm text-gray-600">Dari</label>
                            <TextInput
                                type="date"
                                value={start}
                                onChange={(e) => changeRange('start', e.target.value)}
                            />
                            <label className="text-sm text-gray-600">s/d</label>
                            <TextInput
                                type="date"
                                value={end}
                                onChange={(e) => changeRange('end', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="rounded-lg bg-white p-5 shadow-sm">
                            <p className="text-sm text-gray-500">Penjualan Bersih</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {formatRupiah(report.totals.net)}
                            </p>
                        </div>
                        <div className="rounded-lg bg-white p-5 shadow-sm">
                            <p className="text-sm text-gray-500">Total HPP</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {formatRupiah(report.totals.hpp)}
                            </p>
                        </div>
                        <div className="rounded-lg bg-white p-5 shadow-sm">
                            <p className="text-sm text-gray-500">Laba Kotor / Margin</p>
                            <p className={`mt-1 text-lg font-semibold ${totalGrossProfitClass}`}>
                                {formatRupiah(report.totals.gross_profit)}{' '}
                                <span className="text-sm font-normal text-gray-500">
                                    ({formatMargin(report.totals.margin)})
                                </span>
                            </p>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="mb-3 flex items-center justify-between">
                            <div>
                                <h3 className="font-semibold text-gray-900">
                                    Rekap per Produk
                                </h3>
                                <p className="text-xs text-gray-500">
                                    HPP memakai biaya saat transaksi terjadi (moving average
                                    saat itu), bukan harga beli terkini.
                                </p>
                            </div>
                            <div className="flex items-center gap-2 text-sm">
                                <span className="text-gray-500">Urutkan</span>
                                <button
                                    type="button"
                                    onClick={() => changeSort('gross_profit')}
                                    className={
                                        sort === 'gross_profit'
                                            ? 'font-semibold text-primary'
                                            : 'text-gray-500 hover:text-gray-700'
                                    }
                                >
                                    Laba Kotor
                                </button>
                                <span className="text-gray-300">|</span>
                                <button
                                    type="button"
                                    onClick={() => changeSort('margin')}
                                    className={
                                        sort === 'margin'
                                            ? 'font-semibold text-primary'
                                            : 'text-gray-500 hover:text-gray-700'
                                    }
                                >
                                    Margin
                                </button>
                            </div>
                        </div>
                        {report.by_product.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                Tidak ada produk terjual pada periode ini.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wider text-gray-500">
                                            <th className="py-2 pr-4 font-medium">Produk</th>
                                            <th className="py-2 pr-4 text-right font-medium">Qty</th>
                                            <th className="py-2 pr-4 text-right font-medium">Bersih</th>
                                            <th className="py-2 pr-4 text-right font-medium">HPP</th>
                                            <th className="py-2 pr-4 text-right font-medium">Laba Kotor</th>
                                            <th className="py-2 text-right font-medium">Margin</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {report.by_product.map((row) => (
                                            <tr key={row.product_id} className="border-b border-gray-100">
                                                <td className="py-2 pr-4 text-gray-900">
                                                    {row.product_name}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-gray-600">
                                                    {formatQty(row.qty)}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-gray-600">
                                                    {formatRupiah(row.net)}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-gray-600">
                                                    {formatRupiah(row.hpp)}
                                                </td>
                                                <td
                                                    className={`py-2 pr-4 text-right font-medium ${
                                                        Number(row.gross_profit) >= 0
                                                            ? 'text-gray-900'
                                                            : 'text-red-600'
                                                    }`}
                                                >
                                                    {formatRupiah(row.gross_profit)}
                                                </td>
                                                <td className="py-2 text-right text-gray-600">
                                                    {formatMargin(row.margin)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="font-semibold text-gray-900">
                                            <td className="py-2 pr-4">Total</td>
                                            <td className="py-2 pr-4 text-right">
                                                {formatQty(
                                                    report.by_product.reduce(
                                                        (sum, row) => sum + Number(row.qty),
                                                        0,
                                                    ),
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 text-right">
                                                {formatRupiah(report.totals.net)}
                                            </td>
                                            <td className="py-2 pr-4 text-right">
                                                {formatRupiah(report.totals.hpp)}
                                            </td>
                                            <td className="py-2 pr-4 text-right">
                                                {formatRupiah(report.totals.gross_profit)}
                                            </td>
                                            <td className="py-2 text-right">
                                                {formatMargin(report.totals.margin)}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
