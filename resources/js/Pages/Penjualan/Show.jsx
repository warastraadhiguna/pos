import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDecimalID } from '@/utils/decimalFormat';
import { Head, Link } from '@inertiajs/react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

// Lihat komentar identik di Penjualan/Index.jsx -- `occurred_at` (momen
// transaksi sebenarnya, bisa null untuk baris lama) selalu diformat
// eksplisit ke Asia/Jakarta di sini, tidak diasumsikan dari string mentah.
const formatDateTimeWIB = (occurredAt, fallbackDate) => {
    if (!occurredAt) {
        return `${formatDate(fallbackDate)} (jam tidak tercatat)`;
    }
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(occurredAt));
    const get = (type) => parts.find((p) => p.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')} WIB`;
};

const statusLabel = {
    completed: 'Selesai',
    void: 'Batal',
    refunded: 'Refund',
};

const paymentLabel = {
    cash: 'Tunai',
};

export default function Show({ sale }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Transaksi #{sale.id}
                    </h2>
                    <Link href={route('penjualan.receipt', sale.id)} target="_blank">
                        <PrimaryButton>Cetak Ulang Struk</PrimaryButton>
                    </Link>
                </div>
            }
        >
            <Head title={`Transaksi #${sale.id}`} />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                            <div>
                                <div className="text-gray-500">Tanggal &amp; Jam</div>
                                <div className="font-medium text-gray-900">
                                    {formatDateTimeWIB(sale.occurred_at, sale.date)}
                                </div>
                            </div>
                            <div>
                                <div className="text-gray-500">Pembayaran</div>
                                <div className="font-medium text-gray-900">
                                    {paymentLabel[sale.payment_method] ?? sale.payment_method}
                                </div>
                            </div>
                            <div>
                                <div className="text-gray-500">Status</div>
                                <div className="font-medium text-gray-900">
                                    {statusLabel[sale.status] ?? sale.status}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Produk
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Qty
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Harga
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Subtotal
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {sale.lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {line.product_name ?? line.product.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDecimalID(line.qty)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatRupiah(line.unit_price)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-900">
                                            {formatRupiah(line.line_total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="bg-gray-50">
                                <tr>
                                    <td colSpan={4} className="px-6 py-3 text-right text-sm text-gray-700">
                                        <div>Subtotal: {formatRupiah(sale.subtotal)}</div>
                                        <div>Pajak: {formatRupiah(sale.tax_total)}</div>
                                        <div className="font-semibold">
                                            Total: {formatRupiah(sale.grand_total)}
                                        </div>
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
