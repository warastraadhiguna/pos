import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};
const formatDate = (value) => String(value).slice(0, 10);

const statusLabel = { lunas: 'Lunas', sebagian: 'Sebagian', belum: 'Belum Dibayar' };
const statusClass = {
    lunas: 'text-green-700',
    sebagian: 'text-amber-600',
    belum: 'text-red-600',
};

export default function SupplierPayableShow({ supplier, asOf, outstanding, notas }) {
    const changeDate = (e) => {
        router.get(
            route('laporan.hutang.show', supplier.id),
            { as_of: e.target.value },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Hutang — {supplier.name}
                </h2>
            }
        >
            <Head title={`Hutang — ${supplier.name}`} />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                        <Link href={route('laporan.hutang')} className="text-sm text-gray-500 hover:text-gray-700">
                            ← Kembali ke Daftar Hutang
                        </Link>
                        <div className="flex items-center gap-2">
                            <label className="text-sm text-gray-600">Per tanggal</label>
                            <TextInput type="date" value={asOf} onChange={changeDate} />
                        </div>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="flex justify-between text-sm">
                            <span className="text-gray-600">Sisa Hutang (agregat)</span>
                            <span className="text-lg font-semibold text-gray-900">
                                {formatRupiah(outstanding)}
                            </span>
                        </div>
                        <div className="mt-4">
                            <Link href={route('pembelian.supplier-payments.create', { supplier_id: supplier.id })}>
                                <span className="inline-flex items-center rounded-md border border-transparent bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-primary-dark">
                                    Bayar Hutang
                                </span>
                            </Link>
                        </div>
                    </div>

                    <div>
                        <h3 className="mb-3 font-semibold text-gray-900">Rincian per Nota</h3>
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Nota
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Tanggal
                                        </th>
                                        <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Total Nota
                                        </th>
                                        <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Dialokasikan
                                        </th>
                                        <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Sisa
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {notas.map((nota) => (
                                        <tr key={nota.goods_receipt_id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                <Link
                                                    href={route('pembelian.purchase-orders.show', nota.purchase_order_id)}
                                                    className="text-primary hover:text-primary-dark"
                                                >
                                                    Nota #{nota.goods_receipt_id} (PO-{nota.purchase_order_id})
                                                </Link>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {formatDate(nota.date)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                                {formatRupiah(nota.nota_total)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                                {formatRupiah(nota.allocated)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold text-gray-900">
                                                {formatRupiah(nota.remaining)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm">
                                                <span className={statusClass[nota.status]}>
                                                    {statusLabel[nota.status]}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                    {notas.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="px-6 py-6 text-center text-sm text-gray-500">
                                                Belum ada nota kredit untuk supplier ini.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
