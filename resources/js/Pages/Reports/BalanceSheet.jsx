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
                {account.code ? `${account.code} — ${account.name}` : account.name}
            </span>
            <span className="text-gray-900">{formatRupiah(account.balance)}</span>
        </div>
    ));
}

export default function BalanceSheet({ asOf, report }) {
    const changeDate = (e) => {
        router.get(
            route('laporan.neraca'),
            { as_of: e.target.value },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Neraca
                </h2>
            }
        >
            <Head title="Neraca" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                        <div className="flex gap-4 text-sm">
                            <Link
                                href={route('laporan.neraca')}
                                className="font-semibold text-primary"
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
                            <label className="text-sm text-gray-600">
                                Per tanggal
                            </label>
                            <TextInput
                                type="date"
                                value={asOf}
                                onChange={changeDate}
                            />
                        </div>
                    </div>

                    {!report.is_balanced && (
                        <div className="rounded-md bg-red-50 p-4 text-sm font-medium text-red-700">
                            Neraca tidak balance — Aset ({formatRupiah(report.total_assets)})
                            ≠ Liabilitas + Ekuitas (
                            {formatRupiah(report.total_liabilities_and_equity)}). Ini
                            seharusnya tidak terjadi kalau semua transaksi lewat
                            PostingService — periksa data jurnal.
                        </div>
                    )}

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">Aset</h3>
                        <AccountRows accounts={report.assets} />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Aset</span>
                            <span>{formatRupiah(report.total_assets)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">
                            Liabilitas
                        </h3>
                        <AccountRows accounts={report.liabilities} />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Liabilitas</span>
                            <span>{formatRupiah(report.total_liabilities)}</span>
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <h3 className="mb-2 font-semibold text-gray-900">Ekuitas</h3>
                        <AccountRows accounts={report.equity} />
                        <div className="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                            <span>Total Ekuitas</span>
                            <span>{formatRupiah(report.total_equity)}</span>
                        </div>
                        <p className="mt-2 text-xs text-gray-400">
                            "Laba/Rugi Berjalan" adalah akumulasi Pendapatan − Beban
                            sampai tanggal ini, dihitung langsung dari jurnal — bukan
                            hasil jurnal penutup, karena sistem ini belum punya proses
                            tutup buku periode. Prive tampil sebagai pengurang (angka
                            negatif) terhadap Modal — bukan beban usaha, sehingga
                            tidak pernah muncul di Laba Rugi.
                        </p>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="flex justify-between text-sm font-semibold text-gray-900">
                            <span>Total Liabilitas + Ekuitas</span>
                            <span>
                                {formatRupiah(
                                    report.total_liabilities_and_equity,
                                )}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
