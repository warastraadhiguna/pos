import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDecimalID } from '@/utils/decimalFormat';
import { Head, router, useForm } from '@inertiajs/react';
import { useRef } from 'react';

const formatDate = (value) => String(value).slice(0, 10);

const statusLabel = {
    draft: 'Draft (belum diposting)',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

export default function Show({ stockOpname, liveStock = {}, movementsSinceStart = {}, cancelWarnings = [] }) {
    const isDraft = stockOpname.status === 'draft';
    const isCompleted = stockOpname.status === 'completed';

    // Draft: cukup konfirmasi. Sudah diposting: stok & jurnalnya dibalik,
    // alasan wajib, dan kalau stok akan minus wajib konfirmasi peringatan
    // (lihat StockOpnameService::cancelWarnings()).
    const cancelOpname = () => {
        const warnings = cancelWarnings.map(
            (w) =>
                `Stok ${w.name} tinggal ${formatDecimalID(w.stock)}, tambahan opname yang ditarik ${formatDecimalID(w.qty)}. Stok akan jadi minus.`,
        );
        const message =
            warnings.length > 0
                ? `PERINGATAN — OPN-${stockOpname.id}:\n\n- ${warnings.join('\n- ')}\n\nTetap batalkan?`
                : isCompleted
                  ? `Batalkan OPN-${stockOpname.id}? Penyesuaian stok dan jurnalnya akan dibalik.`
                  : `Batalkan draft OPN-${stockOpname.id}?`;
        if (!window.confirm(message)) return;

        let reason = null;
        if (isCompleted) {
            reason = window.prompt('Alasan pembatalan:');
            if (reason === null) return;
            if (reason.trim() === '') {
                window.alert('Alasan wajib diisi.');
                return;
            }
            reason = reason.trim();
        }

        router.post(route('stock-opname.cancel', stockOpname.id), {
            reason,
            confirm: warnings.length > 0,
        });
    };

    // Draft: selisih saat posting dihitung dari stok TERKINI, bukan stok saat
    // draft dibuat -- tampilkan yang terkini supaya angka di layar sama
    // dengan yang akan diposting (lihat StockOpnameService::postOpname()).
    const systemQtyOf = (line) =>
        isDraft ? (liveStock[line.item_id] ?? line.system_qty) : line.system_qty;
    const movedSinceStart = (line) => movementsSinceStart[line.item_id];
    const anyMoved = isDraft && stockOpname.lines.some((line) => movedSinceStart(line));

    const { data, setData, post, processing, errors } = useForm({
        date: new Date().toISOString().slice(0, 10),
        lines: stockOpname.lines.map((line) => ({
            stock_opname_line_id: line.id,
            counted_qty: systemQtyOf(line),
        })),
        confirm_stock_changed: false,
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

    const diffOf = (line, index) =>
        isDraft
            ? Number(data.lines[index].counted_qty || 0) - Number(systemQtyOf(line))
            : line.diff_qty;

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

                        {stockOpname.status === 'cancelled' && (
                            <div className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800">
                                Dibatalkan {stockOpname.cancelled_at ? formatDate(stockOpname.cancelled_at) : ''}
                                {stockOpname.cancelled_by ? ` oleh ${stockOpname.cancelled_by.name}` : ''}
                                {stockOpname.cancel_reason ? ` — ${stockOpname.cancel_reason}` : ''}
                            </div>
                        )}

                        {stockOpname.status !== 'cancelled' && (
                            <div className="mt-4">
                                <DangerButton type="button" onClick={cancelOpname}>
                                    Batalkan Opname
                                </DangerButton>
                            </div>
                        )}
                    </div>

                    <form ref={formRef} onSubmit={submit} onKeyDown={handleFormKeyDown}>
                        <div className="w-full overflow-x-auto overscroll-x-contain bg-white shadow-sm sm:rounded-lg">
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
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            {isDraft ? 'Selisih (perkiraan)' : 'Selisih'}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {stockOpname.lines.map((line, index) => (
                                        <tr key={line.id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {line.item.sku} —{' '}
                                                {line.item.name}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-600">
                                                <div className="whitespace-nowrap">
                                                    {formatDecimalID(systemQtyOf(line))}{' '}
                                                    {line.item.base_uom?.code}
                                                </div>
                                                {isDraft && movedSinceStart(line) && (
                                                    <div className="mt-1 text-xs text-amber-700">
                                                        Berubah sejak opname dibuat
                                                        (masuk {formatDecimalID(movedSinceStart(line).qty_in)},
                                                        keluar {formatDecimalID(movedSinceStart(line).qty_out)}).
                                                        Hitung ulang.
                                                    </div>
                                                )}
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
                                            <td
                                                className={
                                                    'whitespace-nowrap px-6 py-4 text-sm font-medium ' +
                                                    diffClass(diffOf(line, index))
                                                }
                                            >
                                                {Number(diffOf(line, index)) > 0 ? '+' : ''}
                                                {formatDecimalID(diffOf(line, index))}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {isDraft && (
                            <div className="mt-4 space-y-3">
                                {anyMoved && (
                                    <label className="flex items-start gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                                        <input
                                            type="checkbox"
                                            className="mt-0.5"
                                            checked={data.confirm_stock_changed}
                                            onChange={(e) => setData('confirm_stock_changed', e.target.checked)}
                                        />
                                        <span>
                                            Ada barang masuk/keluar sejak opname ini dibuat. Saya sudah
                                            menghitung ulang barang yang ditandai SETELAH perubahan itu,
                                            jadi hasil hitung di atas adalah stok fisik saat ini.
                                        </span>
                                    </label>
                                )}
                                <PrimaryButton disabled={processing || (anyMoved && !data.confirm_stock_changed)}>
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
