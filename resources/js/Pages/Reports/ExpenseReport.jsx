import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

export default function ExpenseReport({ start, end, expenses, totalExpense }) {
    const changeRange = (field, value) => {
        router.get(
            route('laporan.beban'),
            { start: field === 'start' ? value : start, end: field === 'end' ? value : end },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Laporan Beban Operasional
                </h2>
            }
        >
            <Head title="Laporan Beban Operasional" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                        <div className="flex gap-4 text-sm">
                            <Link href={route('laporan.laba-rugi')} className="text-gray-500 hover:text-gray-700">
                                Laba Rugi
                            </Link>
                            <Link href={route('laporan.beban')} className="font-semibold text-primary">
                                Beban Operasional
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
                            Beban Operasional per Jenis
                        </h3>
                        {expenses.map((account) => (
                            <div key={account.code} className="flex justify-between py-1 text-sm">
                                <span className="text-gray-600">
                                    {account.code} — {account.name}
                                </span>
                                <span className="text-gray-900">{formatRupiah(account.balance)}</span>
                            </div>
                        ))}
                        {expenses.length === 0 && (
                            <p className="py-2 text-sm text-gray-500">
                                Tidak ada beban operasional pada periode ini.
                            </p>
                        )}
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Beban Operasional</span>
                            <span>{formatRupiah(totalExpense)}</span>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
