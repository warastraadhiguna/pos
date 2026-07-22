import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

function AccountRows({ accounts }) {
    return accounts.map((account) => (
        <div key={account.code ?? account.name} className="flex justify-between py-1 text-sm">
            <span className="text-gray-600">
                {account.code} — {account.name}
            </span>
            <span className="text-gray-900">{formatRupiah(account.balance)}</span>
        </div>
    ));
}

export default function IncomeStatement({ start, end, report }) {
    const changeRange = (field, value) => {
        router.get(
            route('laporan.laba-rugi'),
            { start: field === 'start' ? value : start, end: field === 'end' ? value : end },
            { preserveState: true, preserveScroll: true },
        );
    };

    const netIncomeClass =
        Number(report.net_income) >= 0 ? 'text-green-700' : 'text-red-600';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Laba Rugi
                </h2>
            }
        >
            <Head title="Laba Rugi" />

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
                                className="font-semibold text-primary"
                            >
                                Laba Rugi
                            </Link>
                            <Link
                                href={route('laporan.beban')}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                Beban Operasional
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

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">
                            Pendapatan
                        </h3>
                        <AccountRows accounts={report.revenues} />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Pendapatan</span>
                            <span>{formatRupiah(report.total_revenue)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">
                            Harga Pokok Penjualan
                        </h3>
                        <AccountRows accounts={report.cogs_expenses} />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total HPP</span>
                            <span>{formatRupiah(report.total_cogs)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="flex justify-between text-base font-semibold text-gray-900">
                            <span>Laba Kotor</span>
                            <span>{formatRupiah(report.gross_profit)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">Beban Operasional</h3>
                        <AccountRows accounts={report.operational_expenses} />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Beban Operasional</span>
                            <span>{formatRupiah(report.total_operational_expense)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className={`flex justify-between text-base font-semibold ${netIncomeClass}`}>
                            <span>Laba / Rugi Bersih</span>
                            <span>{formatRupiah(report.net_income)}</span>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
