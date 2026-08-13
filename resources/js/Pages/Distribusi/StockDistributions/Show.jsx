import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const statusBadge = {
    draft: (
        <span className="rounded-full bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800">
            Draft
        </span>
    ),
    completed: (
        <span className="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">
            Selesai
        </span>
    ),
    cancelled: (
        <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">
            Dibatalkan
        </span>
    ),
};

const formatQty = (value) => Number(value).toLocaleString('id-ID', { maximumFractionDigits: 4 });
const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');

export default function Show({ distribution, shortages }) {
    const [showExecuteConfirm, setShowExecuteConfirm] = useState(false);
    const isDraft = distribution.status === 'draft';
    const hasShortages = shortages.length > 0;

    const execute = (confirmInsufficient = false) => {
        router.post(
            route('distribusi.stock-distributions.execute', distribution.id),
            { confirm_insufficient_stock: confirmInsufficient },
            { preserveScroll: true },
        );
    };

    const handleExecuteClick = () => {
        if (hasShortages) {
            setShowExecuteConfirm(true);
        } else {
            execute(false);
        }
    };

    const cancel = () => {
        if (!confirm('Batalkan distribusi ini? Dokumen ini belum menyentuh stok sama sekali.')) return;
        router.post(route('distribusi.stock-distributions.cancel', distribution.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Distribusi Stok #{distribution.id}
                </h2>
            }
        >
            <Head title={`Distribusi Stok #${distribution.id}`} />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="flex items-start justify-between">
                            <div className="space-y-1 text-sm text-gray-700">
                                <p>
                                    <span className="text-gray-500">Dari:</span>{' '}
                                    <span className="font-medium">{distribution.source_warehouse.name}</span>
                                </p>
                                <p>
                                    <span className="text-gray-500">Ke:</span>{' '}
                                    <span className="font-medium">{distribution.dest_warehouse.name}</span>
                                </p>
                                <p>
                                    <span className="text-gray-500">Tanggal:</span> {distribution.date}
                                </p>
                                {distribution.notes && (
                                    <p>
                                        <span className="text-gray-500">Catatan:</span> {distribution.notes}
                                    </p>
                                )}
                                <p>
                                    <span className="text-gray-500">Dibuat oleh:</span>{' '}
                                    {distribution.created_by?.name ?? '-'}
                                </p>
                                {distribution.status === 'completed' && (
                                    <p>
                                        <span className="text-gray-500">Dieksekusi oleh:</span>{' '}
                                        {distribution.executed_by?.name ?? '-'} pada{' '}
                                        {distribution.executed_at}
                                    </p>
                                )}
                            </div>
                            <div>{statusBadge[distribution.status] ?? distribution.status}</div>
                        </div>
                    </div>

                    {isDraft && hasShortages && (
                        <div className="rounded-lg bg-yellow-50 p-4 text-sm text-yellow-800 ring-1 ring-yellow-200">
                            <p className="font-medium">Stok gudang pusat tidak cukup untuk:</p>
                            <ul className="mt-1 list-disc pl-5">
                                {shortages.map((s) => (
                                    <li key={s.item_sku}>
                                        {s.item_sku}: diminta {formatQty(s.requested)}, tersedia {formatQty(s.available)}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-2 text-xs">
                                Stok boleh minus (konsisten dengan aplikasi ini) -- eksekusi tetap bisa dilanjutkan dengan konfirmasi.
                            </p>
                        </div>
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Item
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Qty
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        HPP Dipindahkan
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {distribution.lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {line.item.sku} — {line.item.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatQty(line.qty)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {line.unit_cost !== null ? formatRupiah(line.unit_cost) : '— (belum dieksekusi)'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center gap-4">
                        {isDraft && (
                            <>
                                <PrimaryButton onClick={handleExecuteClick}>Eksekusi Transfer</PrimaryButton>
                                <DangerButton onClick={cancel}>Batalkan</DangerButton>
                            </>
                        )}
                        <Link href={route('distribusi.stock-distributions.index')}>
                            <SecondaryButton type="button">Kembali</SecondaryButton>
                        </Link>
                    </div>
                </div>
            </div>

            <Modal show={showExecuteConfirm} onClose={() => setShowExecuteConfirm(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Stok Pusat Tidak Cukup</h3>
                    <p className="mt-2 text-sm text-gray-600">
                        Sebagian item yang didistribusikan melebihi stok gudang pusat saat ini. Stok boleh menjadi minus
                        (konsisten dengan aplikasi ini) -- lanjutkan eksekusi?
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setShowExecuteConfirm(false)}>Batal</SecondaryButton>
                        <PrimaryButton
                            onClick={() => {
                                setShowExecuteConfirm(false);
                                execute(true);
                            }}
                        >
                            Ya, Tetap Eksekusi
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
