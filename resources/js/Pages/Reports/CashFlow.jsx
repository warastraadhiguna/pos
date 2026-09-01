import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

function CashFlowRows({ rows }) {
    return rows.map((row) => (
        <div key={row.label} className="flex justify-between py-1 text-sm">
            <span className="text-gray-600">{row.label}</span>
            <span className="text-gray-900">{formatRupiah(row.balance)}</span>
        </div>
    ));
}

export default function CashFlow({ start, end, report }) {
    const changeRange = (field, value) => {
        router.get(
            route('laporan.arus-kas'),
            {
                start: field === 'start' ? value : start,
                end: field === 'end' ? value : end,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const netChangeClass =
        Number(report.net_change) >= 0 ? 'text-green-700' : 'text-red-600';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Arus Kas
                </h2>
            }
        >
            <Head title="Arus Kas" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-4 shadow-sm">
                        <div className="flex flex-wrap items-center justify-end gap-2">
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

                    {!report.is_balanced && (
                        <div className="rounded-md bg-red-50 p-4 text-sm font-medium text-red-700">
                            Saldo kas akhir hasil hitung ({formatRupiah(report.ending_cash)})
                            ≠ saldo Kas/Bank sungguhan per {report.end} (
                            {formatRupiah(report.actual_ending_cash)}). Kemungkinan ada
                            jenis transaksi baru yang belum dipetakan ke salah satu
                            aktivitas di bawah — periksa
                            FinancialReportService::classifyCashLine().
                        </div>
                    )}

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">
                            Aktivitas Operasi
                        </h3>
                        {report.operating.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                Tidak ada mutasi kas dari aktivitas operasi periode ini.
                            </p>
                        ) : (
                            <CashFlowRows rows={report.operating} />
                        )}
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Kas dari Aktivitas Operasi</span>
                            <span>{formatRupiah(report.total_operating)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">
                            Aktivitas Investasi
                        </h3>
                        {report.investing.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                Tidak ada mutasi kas dari aktivitas investasi periode ini.
                            </p>
                        ) : (
                            <CashFlowRows rows={report.investing} />
                        )}
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Kas dari Aktivitas Investasi</span>
                            <span>{formatRupiah(report.total_investing)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">
                            Aktivitas Pendanaan
                        </h3>
                        {report.financing.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                Tidak ada mutasi kas dari aktivitas pendanaan periode ini.
                            </p>
                        ) : (
                            <CashFlowRows rows={report.financing} />
                        )}
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Kas dari Aktivitas Pendanaan</span>
                            <span>{formatRupiah(report.total_financing)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className={`flex justify-between text-base font-semibold ${netChangeClass}`}>
                            <span>Kenaikan (Penurunan) Kas Bersih</span>
                            <span>{formatRupiah(report.net_change)}</span>
                        </div>
                        <div className="mt-3 flex justify-between border-t border-gray-200 pt-2 text-sm text-gray-600">
                            <span>Saldo Kas Awal Periode</span>
                            <span>{formatRupiah(report.beginning_cash)}</span>
                        </div>
                        <div className="flex justify-between text-sm text-gray-600">
                            <span>Saldo Kas Akhir Periode</span>
                            <span>{formatRupiah(report.ending_cash)}</span>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
