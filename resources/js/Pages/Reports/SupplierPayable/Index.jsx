import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

export default function SupplierPayableIndex({ asOf, rows, total }) {
    const changeDate = (e) => {
        router.get(
            route('laporan.hutang'),
            { as_of: e.target.value },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Hutang Supplier
                </h2>
            }
        >
            <Head title="Hutang Supplier" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                        <div className="flex flex-wrap gap-4 text-sm">
                            <Link href={route('laporan.neraca')} className="text-gray-500 hover:text-gray-700">
                                Neraca
                            </Link>
                            <Link href={route('laporan.laba-rugi')} className="text-gray-500 hover:text-gray-700">
                                Laba Rugi
                            </Link>
                            <Link href={route('laporan.penjualan')} className="text-gray-500 hover:text-gray-700">
                                Penjualan
                            </Link>
                            <Link href={route('laporan.laba-produk')} className="text-gray-500 hover:text-gray-700">
                                Laba per Produk
                            </Link>
                            <Link href={route('laporan.ppn')} className="text-gray-500 hover:text-gray-700">
                                PPN
                            </Link>
                            <Link href={route('laporan.hutang')} className="font-semibold text-primary">
                                Hutang Supplier
                            </Link>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-sm text-gray-600">Per tanggal</label>
                            <TextInput type="date" value={asOf} onChange={changeDate} />
                        </div>
                    </div>

                    <p className="text-sm text-gray-500">
                        Sisa hutang dihitung langsung dari jurnal (bukan dari tabel ringkasan
                        manapun) — totalnya harus persis sama dengan saldo akun 2-1000 (Hutang
                        Usaha) di Neraca pada tanggal yang sama. Penerimaan barang bermetode
                        tunai tidak pernah muncul di sini karena tidak pernah menyentuh akun
                        Hutang Usaha.
                    </p>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Supplier
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Total Kredit
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Total Dibayar
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Sisa Hutang
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {rows.map((row) => (
                                    <tr key={row.supplier_id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            <Link
                                                href={route('laporan.hutang.show', row.supplier_id)}
                                                className="text-primary hover:text-primary-dark"
                                            >
                                                {row.supplier_name}
                                            </Link>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatRupiah(row.total_credit)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatRupiah(row.total_paid)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold text-gray-900">
                                            {formatRupiah(row.outstanding)}
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Belum ada supplier.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            <tfoot className="bg-gray-50">
                                <tr>
                                    <td colSpan={3} className="px-6 py-3 text-right text-sm font-semibold text-gray-900">
                                        Total Hutang (harus cocok dengan Neraca)
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-3 text-right text-sm font-semibold text-gray-900">
                                        {formatRupiah(total)}
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
