import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDecimalID } from '@/utils/decimalFormat';
import { Head, Link } from '@inertiajs/react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

const statusLabel = {
    open: 'Open',
    partial: 'Sebagian Diterima',
    received: 'Diterima Penuh',
    cancelled: 'Dibatalkan',
};

const notaBadgeLabel = {
    tunai: 'Tunai',
    lunas: 'Kredit — Lunas',
    sebagian: 'Kredit — Sebagian',
    belum: 'Kredit — Belum Dibayar',
};

const notaBadgeClass = {
    tunai: 'bg-gray-100 text-gray-700',
    lunas: 'bg-green-100 text-green-700',
    sebagian: 'bg-amber-100 text-amber-700',
    belum: 'bg-red-100 text-red-700',
};

function NotaBadge({ status }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${notaBadgeClass[status]}`}
        >
            {notaBadgeLabel[status]}
        </span>
    );
}

export default function Show({ purchaseOrder, lines, receipts }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    PO-{purchaseOrder.id}
                </h2>
            }
        >
            <Head title={`PO-${purchaseOrder.id}`} />

            <div className="py-12">
                <div className="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                            <div>
                                <div className="text-gray-500">
                                    Supplier
                                </div>
                                <div className="font-medium text-gray-900">
                                    {purchaseOrder.supplier.name}
                                </div>
                            </div>
                            <div>
                                <div className="text-gray-500">Gudang</div>
                                <div className="font-medium text-gray-900">
                                    {purchaseOrder.warehouse.name}
                                </div>
                            </div>
                            <div>
                                <div className="text-gray-500">
                                    Tanggal
                                </div>
                                <div className="font-medium text-gray-900">
                                    {formatDate(purchaseOrder.date)}
                                </div>
                            </div>
                            <div>
                                <div className="text-gray-500">Status</div>
                                <div className="font-medium text-gray-900">
                                    {statusLabel[purchaseOrder.status]}
                                </div>
                            </div>
                        </div>

                        {purchaseOrder.notes && (
                            <div className="mt-4 rounded-md bg-gray-50 p-3 text-sm">
                                <div className="text-gray-500">Catatan</div>
                                <div className="whitespace-pre-wrap text-gray-800">
                                    {purchaseOrder.notes}
                                </div>
                            </div>
                        )}

                        {purchaseOrder.status !== 'received' && (
                            <div className="mt-4">
                                <Link
                                    href={route(
                                        'pembelian.purchase-orders.receive.create',
                                        purchaseOrder.id,
                                    )}
                                >
                                    <PrimaryButton>
                                        Terima Barang
                                    </PrimaryButton>
                                </Link>
                            </div>
                        )}
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Item
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Dipesan
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Sudah Diterima
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Harga/Ukuran
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Pajak
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {line.item.sku} —{' '}
                                            {line.item.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDecimalID(line.qty)}{' '}
                                            {line.purchase_uom.code}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDecimalID(
                                                line.received_qty_base_uom,
                                            )}{' '}
                                            {line.item.base_uom?.code}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatRupiah(line.unit_price)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {line.tax_rate?.name ?? '-'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="bg-gray-50">
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-6 py-3 text-right text-sm text-gray-700"
                                    >
                                        <div>
                                            Subtotal:{' '}
                                            {formatRupiah(
                                                purchaseOrder.subtotal,
                                            )}
                                        </div>
                                        <div>
                                            Pajak:{' '}
                                            {formatRupiah(
                                                purchaseOrder.tax_total,
                                            )}
                                        </div>
                                        <div className="font-semibold">
                                            Total:{' '}
                                            {formatRupiah(
                                                purchaseOrder.grand_total,
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div>
                        <h3 className="mb-3 font-semibold text-gray-900">
                            Riwayat Penerimaan
                        </h3>
                        {receipts.length === 0 && (
                            <p className="text-sm text-gray-500">
                                Belum ada penerimaan barang.
                            </p>
                        )}
                        <div className="space-y-3">
                            {receipts.map((receipt) => (
                                <div
                                    key={receipt.id}
                                    className="rounded-lg bg-white p-4 shadow-sm"
                                >
                                    <div className="mb-2 flex items-center justify-between">
                                        <div className="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <span>
                                                Penerimaan #{receipt.id} —{' '}
                                                {formatDate(receipt.date)}
                                            </span>
                                            <NotaBadge
                                                status={
                                                    receipt.nota_status.status
                                                }
                                            />
                                        </div>
                                        {(receipt.nota_status.status ===
                                            'sebagian' ||
                                            receipt.nota_status.status ===
                                                'belum') && (
                                            <Link
                                                href={route(
                                                    'pembelian.supplier-payments.create',
                                                    {
                                                        supplier_id:
                                                            purchaseOrder
                                                                .supplier.id,
                                                        goods_receipt_id:
                                                            receipt.id,
                                                    },
                                                )}
                                            >
                                                <SecondaryButton type="button">
                                                    Bayar
                                                </SecondaryButton>
                                            </Link>
                                        )}
                                    </div>
                                    <ul className="space-y-1 text-sm text-gray-600">
                                        {receipt.lines.map((line) => (
                                            <li key={line.id}>
                                                {line.item.sku} —{' '}
                                                {line.item.name}:{' '}
                                                {formatDecimalID(line.qty)} @{' '}
                                                {formatRupiah(
                                                    line.unit_cost,
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                    {receipt.notes && (
                                        <div className="mt-2 rounded-md bg-gray-50 p-2 text-sm">
                                            <div className="text-xs text-gray-500">
                                                Catatan
                                            </div>
                                            <div className="whitespace-pre-wrap text-gray-700">
                                                {receipt.notes}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
