import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

export default function Index({ period, preview, history }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing } = useForm({
        period,
        date: new Date().toISOString().slice(0, 10),
    });

    const changePeriod = (e) => {
        const newPeriod = e.target.value;
        router.get(route('aset.depreciation.index'), { period: newPeriod }, { preserveState: true });
    };

    const submit = (e) => {
        e.preventDefault();
        setShowConfirm(true);
    };

    const confirmProcess = () => {
        setShowConfirm(false);
        post(route('aset.depreciation.process'));
    };

    const totalPreview = preview.reduce((sum, row) => sum + Number(row.amount), 0);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Proses Penyusutan
                </h2>
            }
        >
            <Head title="Proses Penyusutan" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <form onSubmit={submit} className="space-y-4">
                            <div className="flex flex-wrap items-end gap-4">
                                <div>
                                    <InputLabel htmlFor="period" value="Periode" />
                                    <input
                                        id="period"
                                        type="month"
                                        className="mt-1 h-10 block rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                                        value={period}
                                        onChange={changePeriod}
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="date" value="Tanggal Jurnal" />
                                    <input
                                        id="date"
                                        type="date"
                                        className="mt-1 h-10 block rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                                        value={data.date}
                                        onChange={(e) => setData('date', e.target.value)}
                                        required
                                    />
                                </div>
                            </div>

                            <p className="text-sm text-gray-500">
                                {preview.length === 0
                                    ? 'Tidak ada aset yang perlu diproses untuk periode ini (sudah diproses semua, atau sudah habis disusutkan).'
                                    : `${preview.length} aset akan diproses untuk periode ${period}.`}
                            </p>

                            {preview.length > 0 && (
                                <div className="overflow-hidden rounded-md border border-gray-200">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                                    Aset
                                                </th>
                                                <th className="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                                    Jumlah
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200 bg-white">
                                            {preview.map((row) => (
                                                <tr key={row.fixed_asset_id}>
                                                    <td className="px-4 py-2 text-sm text-gray-900">{row.name}</td>
                                                    <td className="px-4 py-2 text-right text-sm text-gray-600">
                                                        {formatRupiah(row.amount)}
                                                    </td>
                                                </tr>
                                            ))}
                                            <tr className="bg-gray-50 font-semibold">
                                                <td className="px-4 py-2 text-sm text-gray-900">Total</td>
                                                <td className="px-4 py-2 text-right text-sm text-gray-900">
                                                    {formatRupiah(totalPreview)}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            <PrimaryButton disabled={processing || preview.length === 0}>
                                Proses Penyusutan {period}
                            </PrimaryButton>
                        </form>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <h3 className="p-4 font-semibold text-gray-900">Riwayat Penyusutan</h3>
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Periode
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tanggal
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Aset
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Jumlah
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {history.map((entry) => (
                                    <tr key={entry.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {entry.period}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDate(entry.date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {entry.fixed_asset_name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatRupiah(entry.amount)}
                                        </td>
                                    </tr>
                                ))}
                                {history.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Belum ada penyusutan yang diproses.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={showConfirm} onClose={() => setShowConfirm(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">
                        Konfirmasi Proses Penyusutan
                    </h3>
                    <p className="mt-2 text-sm text-gray-600">
                        {preview.length} aset akan diposting untuk periode{' '}
                        <span className="font-medium">{period}</span>, total{' '}
                        <span className="font-medium">{formatRupiah(totalPreview)}</span>.
                    </p>
                    <p className="mt-2 text-sm text-gray-600">Lanjutkan?</p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setShowConfirm(false)}>
                            Batal, Periksa Lagi
                        </SecondaryButton>
                        <PrimaryButton disabled={processing} onClick={confirmProcess}>
                            Ya, Proses
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
