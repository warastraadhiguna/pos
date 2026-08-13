import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

/**
 * Multi-Cabang Lapisan 4 -- semua cabang aktif bersanding: omzet & laba
 * kotor (dari penjualan), laba bersih (dari jurnal, sudah termasuk beban
 * operasional), dan nilai stok saat ini. TIGA sumber independen (bukan
 * satu rumus besar) -- lihat SalesReportService::salesByOutlet()/
 * FinancialReportService::incomeStatementByOutlet(), masing-masing sudah
 * dipakai & teruji di laporan lain, jadi tidak ada rumus kedua yang bisa
 * menyimpang dari laporan-laporan itu.
 */
export default function BranchComparison({ start, end, salesByOutlet, incomeByOutlet, stockValueByOutlet }) {
    const changeRange = (field, value) => {
        router.get(
            route('laporan.perbandingan-cabang'),
            { start: field === 'start' ? value : start, end: field === 'end' ? value : end },
            { preserveState: true, preserveScroll: true },
        );
    };

    const incomeByOutletId = Object.fromEntries(incomeByOutlet.map((row) => [row.outlet_id, row]));
    const stockValueByOutletId = Object.fromEntries(stockValueByOutlet.map((row) => [row.outlet_id, row]));

    const totals = salesByOutlet.reduce(
        (acc, row) => ({
            gross: acc.gross + Number(row.gross),
            grossProfit: acc.grossProfit + Number(row.gross_profit),
            netIncome: acc.netIncome + Number(incomeByOutletId[row.outlet_id]?.net_income ?? 0),
        }),
        { gross: 0, grossProfit: 0, netIncome: 0 },
    );

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Perbandingan Cabang
                </h2>
            }
        >
            <Head title="Perbandingan Cabang" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                        <div className="flex gap-4 text-sm">
                            <Link href={route('laporan.penjualan')} className="text-gray-500 hover:text-gray-700">
                                Laporan Penjualan
                            </Link>
                            <Link href={route('laporan.laba-rugi')} className="text-gray-500 hover:text-gray-700">
                                Laba Rugi
                            </Link>
                            <span className="font-semibold text-primary">Perbandingan Cabang</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-sm text-gray-600">Dari</label>
                            <TextInput type="date" value={start} onChange={(e) => changeRange('start', e.target.value)} />
                            <label className="text-sm text-gray-600">s/d</label>
                            <TextInput type="date" value={end} onChange={(e) => changeRange('end', e.target.value)} />
                        </div>
                    </div>

                    <p className="rounded-lg bg-blue-50 p-4 text-sm text-blue-800 ring-1 ring-blue-100">
                        Omzet &amp; Laba Kotor dari penjualan periode ini. Laba Bersih sudah
                        termasuk beban operasional cabang masing-masing (lihat Laba Rugi,
                        filter per cabang untuk rinciannya). Nilai Stok adalah snapshot SAAT
                        INI (bukan per periode).
                    </p>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Cabang
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Transaksi
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Omzet
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Laba Kotor
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Laba Bersih
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nilai Stok Saat Ini
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {salesByOutlet.map((row) => {
                                    const netIncome = incomeByOutletId[row.outlet_id]?.net_income ?? '0';
                                    const stockValue = stockValueByOutletId[row.outlet_id]?.value ?? '0';

                                    return (
                                        <tr key={row.outlet_id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {row.outlet_name}
                                                {row.is_headquarters && (
                                                    <span className="ml-2 rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">
                                                        Pusat
                                                    </span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                                {row.transaction_count}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-900">
                                                {formatRupiah(row.gross)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                                {formatRupiah(row.gross_profit)}
                                            </td>
                                            <td
                                                className={
                                                    'whitespace-nowrap px-6 py-4 text-right text-sm font-medium ' +
                                                    (Number(netIncome) >= 0 ? 'text-green-700' : 'text-red-600')
                                                }
                                            >
                                                {formatRupiah(netIncome)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                                {formatRupiah(stockValue)}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {salesByOutlet.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Belum ada cabang aktif.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            <tfoot>
                                <tr className="font-semibold text-gray-900">
                                    <td className="px-6 py-3">Total Gabungan</td>
                                    <td className="px-6 py-3 text-right">
                                        {salesByOutlet.reduce((sum, row) => sum + row.transaction_count, 0)}
                                    </td>
                                    <td className="px-6 py-3 text-right">{formatRupiah(totals.gross)}</td>
                                    <td className="px-6 py-3 text-right">{formatRupiah(totals.grossProfit)}</td>
                                    <td className="px-6 py-3 text-right">{formatRupiah(totals.netIncome)}</td>
                                    <td className="px-6 py-3 text-right">
                                        {formatRupiah(stockValueByOutlet.reduce((sum, row) => sum + Number(row.value), 0))}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
