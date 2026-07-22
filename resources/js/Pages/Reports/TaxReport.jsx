import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

function DetailTable({ details, emptyLabel }) {
    if (details.length === 0) {
        return <p className="py-4 text-sm text-gray-500">{emptyLabel}</p>;
    }

    return (
        <table className="mt-2 w-full text-sm">
            <thead>
                <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wider text-gray-500">
                    <th className="py-2 pr-3 font-medium">Tanggal</th>
                    <th className="py-2 pr-3 font-medium">No. Jurnal</th>
                    <th className="py-2 pr-3 font-medium">Sumber</th>
                    <th className="py-2 text-right font-medium">Nilai PPN</th>
                </tr>
            </thead>
            <tbody>
                {details.map((row) => (
                    <tr key={row.journal_id} className="border-b border-gray-100">
                        <td className="py-2 pr-3 text-gray-600">{row.date}</td>
                        <td className="py-2 pr-3 text-gray-600">{row.journal_number}</td>
                        <td className="py-2 pr-3 text-gray-900">{row.source}</td>
                        <td className="py-2 text-right text-gray-900">{formatRupiah(row.amount)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function TaxReport({ start, end, report }) {
    const changeRange = (field, value) => {
        router.get(
            route('laporan.ppn'),
            { start: field === 'start' ? value : start, end: field === 'end' ? value : end },
            { preserveState: true, preserveScroll: true },
        );
    };

    const payableClass =
        Number(report.total_payable) >= 0 ? 'text-gray-900' : 'text-green-700';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Laporan PPN
                </h2>
            }
        >
            <Head title="Laporan PPN" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
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
                                className="text-gray-500 hover:text-gray-700"
                            >
                                Laba per Produk
                            </Link>
                            <Link
                                href={route('laporan.ppn')}
                                className="font-semibold text-primary"
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

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-1 font-semibold text-gray-900">
                            PPN Keluaran — {report.output.account.code} {report.output.account.name}
                        </h3>
                        <DetailTable
                            details={report.output.details}
                            emptyLabel="Tidak ada penjualan ber-PPN pada periode ini."
                        />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total PPN Keluaran</span>
                            <span>{formatRupiah(report.output.total)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-1 font-semibold text-gray-900">
                            PPN Masukan — {report.input.account.code} {report.input.account.name}
                        </h3>
                        <DetailTable
                            details={report.input.details}
                            emptyLabel="Tidak ada pembelian ber-PPN pada periode ini."
                        />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total PPN Masukan</span>
                            <span>{formatRupiah(report.input.total)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className={`flex justify-between text-base font-semibold ${payableClass}`}>
                            <span>
                                {report.is_overpaid
                                    ? 'PPN Lebih Bayar (Total Keluaran − Masukan negatif)'
                                    : 'PPN Harus Disetor'}
                            </span>
                            <span>{formatRupiah(report.total_payable)}</span>
                        </div>
                        {report.is_overpaid && (
                            <p className="mt-2 text-xs text-gray-500">
                                PPN Masukan lebih besar dari PPN Keluaran pada periode ini — nilai
                                negatif berarti lebih bayar, bisa dikompensasi ke periode berikutnya.
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
