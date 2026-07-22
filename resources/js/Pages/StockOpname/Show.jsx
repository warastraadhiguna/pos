import InputError from '@/Components/InputError';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDecimalID } from '@/utils/decimalFormat';
import { Head, useForm } from '@inertiajs/react';
import { useRef } from 'react';

const formatDate = (value) => String(value).slice(0, 10);

const statusLabel = {
    draft: 'Draft (belum diposting)',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

export default function Show({ stockOpname }) {
    const isDraft = stockOpname.status === 'draft';

    const { data, setData, post, processing, errors } = useForm({
        date: new Date().toISOString().slice(0, 10),
        lines: stockOpname.lines.map((line) => ({
            stock_opname_line_id: line.id,
            counted_qty: line.system_qty,
        })),
    });

    const updateCountedQty = (index, value) => {
        const updated = data.lines.map((line, i) =>
            i === index ? { ...line, counted_qty: value } : line,
        );
        setData('lines', updated);
    };

    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();
        post(route('stock-opname.post', stockOpname.id));
    };

    // Enter di field hasil hitung fisik TIDAK boleh langsung posting opname
    // (ini menulis jurnal penyesuaian stok) — pola sama dengan form
    // Pembelian: Enter pindah ke field berikutnya, bukan submit.
    const handleFormKeyDown = (e) => {
        if (e.key !== 'Enter' || e.ctrlKey || e.defaultPrevented) return;

        e.preventDefault();

        const focusable = Array.from(
            formRef.current?.querySelectorAll('input, select') ?? [],
        ).filter((el) => !el.disabled);
        const currentIndex = focusable.indexOf(e.target);
        if (currentIndex === -1) return;

        focusable[currentIndex + 1]?.focus();
    };

    const diffClass = (diff) => {
        const value = Number(diff);
        if (value > 0) return 'text-green-700';
        if (value < 0) return 'text-red-600';
        return 'text-gray-500';
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    OPN-{stockOpname.id}
                </h2>
            }
        >
            <Head title={`OPN-${stockOpname.id}`} />

            <div className="py-12">
                <div className="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <div className="text-gray-500">Gudang</div>
                                <div className="font-medium text-gray-900">
                                    {stockOpname.warehouse.name}
                                </div>
                            </div>
                            <div>
                                <div className="text-gray-500">
                                    Tanggal
                                </div>
                                <div className="font-medium text-gray-900">
                                    {formatDate(stockOpname.date)}
                                </div>
                            </div>
                            <div>
                                <div className="text-gray-500">Status</div>
                                <div className="font-medium text-gray-900">
                                    {statusLabel[stockOpname.status]}
                                </div>
                            </div>
                        </div>
                    </div>

                    <form ref={formRef} onSubmit={submit} onKeyDown={handleFormKeyDown}>
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Item
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Stok Sistem
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Hasil Hitung Fisik
                                        </th>
                                        {!isDraft && (
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Selisih
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {stockOpname.lines.map((line, index) => (
                                        <tr key={line.id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {line.item.sku} —{' '}
                                                {line.item.name}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {formatDecimalID(line.system_qty)}{' '}
                                                {line.item.base_uom?.code}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {isDraft ? (
                                                    <>
                                                        <NumberInput
                                                            className="w-32"
                                                            value={
                                                                data.lines[
                                                                    index
                                                                ]
                                                                    .counted_qty
                                                            }
                                                            onChange={(plain) =>
                                                                updateCountedQty(
                                                                    index,
                                                                    plain,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            className="mt-1"
                                                            message={
                                                                errors[
                                                                    `lines.${index}.counted_qty`
                                                                ]
                                                            }
                                                        />
                                                    </>
                                                ) : (
                                                    <>
                                                        {formatDecimalID(line.counted_qty)}{' '}
                                                        {
                                                            line.item
                                                                .base_uom
                                                                ?.code
                                                        }
                                                    </>
                                                )}
                                            </td>
                                            {!isDraft && (
                                                <td
                                                    className={
                                                        'whitespace-nowrap px-6 py-4 text-sm font-medium ' +
                                                        diffClass(
                                                            line.diff_qty,
                                                        )
                                                    }
                                                >
                                                    {Number(line.diff_qty) >
                                                    0
                                                        ? '+'
                                                        : ''}
                                                    {formatDecimalID(line.diff_qty)}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {isDraft && (
                            <div className="mt-4">
                                <PrimaryButton disabled={processing}>
                                    Posting Hasil Opname
                                </PrimaryButton>
                            </div>
                        )}
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
