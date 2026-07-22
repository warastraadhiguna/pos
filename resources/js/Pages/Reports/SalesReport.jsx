import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

const formatQty = (value) => Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });

export default function SalesReport({ start, end, report }) {
    const changeRange = (field, value) => {
        router.get(
            route('laporan.penjualan'),
            { start: field === 'start' ? value : start, end: field === 'end' ? value : end },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Laporan Penjualan
                </h2>
            }
        >
            <Head title="Laporan Penjualan" />

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
                                className="font-semibold text-primary"
                            >
                                Penjualan
                            </Link>
                            <Link
                                href={route('laporan.laba-produk')}
                                className="text-gray-500 hover:text-gray-700"
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
                            <p className="text-sm text-gray-500">Jumlah Transaksi</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {report.totals.transaction_count}
                            </p>
                        </div>
                        <div className="rounded-lg bg-white p-5 shadow-sm">
                            <p className="text-sm text-gray-500">Total Dibayar Pelanggan (Bruto)</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {formatRupiah(report.totals.gross)}
                            </p>
                        </div>
                        <div className="rounded-lg bg-white p-5 shadow-sm">
                            <p className="text-sm text-gray-500">Penjualan Bersih / PPN</p>
                            <p className="mt-1 text-lg font-semibold text-gray-900">
                                {formatRupiah(report.totals.net)}{' '}
                                <span className="text-sm font-normal text-gray-500">
                                    + {formatRupiah(report.totals.tax)} PPN
                                </span>
                            </p>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-3 font-semibold text-gray-900">
                            Rekap per Hari
                        </h3>
                        {report.by_day.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                Tidak ada transaksi pada periode ini.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wider text-gray-500">
                                            <th className="py-2 pr-4 font-medium">Tanggal</th>
                                            <th className="py-2 pr-4 text-right font-medium">Transaksi</th>
                                            <th className="py-2 pr-4 text-right font-medium">Bruto</th>
                                            <th className="py-2 pr-4 text-right font-medium">Bersih</th>
                                            <th className="py-2 text-right font-medium">PPN</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {report.by_day.map((row) => (
                                            <tr key={row.date} className="border-b border-gray-100">
                                                <td className="py-2 pr-4 text-gray-900">{row.date}</td>
                                                <td className="py-2 pr-4 text-right text-gray-600">
                                                    {row.transaction_count}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-gray-900">
                                                    {formatRupiah(row.gross)}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-gray-600">
                                                    {formatRupiah(row.net)}
                                                </td>
                                                <td className="py-2 text-right text-gray-600">
                                                    {formatRupiah(row.tax)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="font-semibold text-gray-900">
                                            <td className="py-2 pr-4">Total</td>
                                            <td className="py-2 pr-4 text-right">
                                                {report.totals.transaction_count}
                                            </td>
                                            <td className="py-2 pr-4 text-right">
                                                {formatRupiah(report.totals.gross)}
                                            </td>
                                            <td className="py-2 pr-4 text-right">
                                                {formatRupiah(report.totals.net)}
                                            </td>
                                            <td className="py-2 text-right">
                                                {formatRupiah(report.totals.tax)}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-1 font-semibold text-gray-900">
                            Rekap per Produk
                        </h3>
                        <p className="mb-3 text-xs text-gray-500">
                            Diurutkan dari kontribusi omzet (bruto) terbesar.
                        </p>
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
                                            <th className="py-2 pr-4 text-right font-medium">Bruto</th>
                                            <th className="py-2 pr-4 text-right font-medium">Bersih</th>
                                            <th className="py-2 text-right font-medium">PPN</th>
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
                                                <td className="py-2 pr-4 text-right text-gray-900">
                                                    {formatRupiah(row.gross)}
                                                </td>
                                                <td className="py-2 pr-4 text-right text-gray-600">
                                                    {formatRupiah(row.net)}
                                                </td>
                                                <td className="py-2 text-right text-gray-600">
                                                    {formatRupiah(row.tax)}
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
                                                {formatRupiah(report.totals.gross)}
                                            </td>
                                            <td className="py-2 pr-4 text-right">
                                                {formatRupiah(report.totals.net)}
                                            </td>
                                            <td className="py-2 text-right">
                                                {formatRupiah(report.totals.tax)}
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
