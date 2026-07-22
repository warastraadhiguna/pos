import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDecimalID } from '@/utils/decimalFormat';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export default function Create({ purchaseOrder, lines, cashAccounts }) {
    const { data, setData, post, transform, processing, errors } = useForm({
        date: new Date().toISOString().slice(0, 10),
        // Default 'credit' -- perilaku lama tetap default, "Tunai" adalah
        // pilihan sadar yang harus dipilih eksplisit.
        payment_method: 'credit',
        // Hanya relevan/dikirim saat payment_method='cash'. Default Kas.
        cash_account_code: cashAccounts[0]?.code ?? '',
        lines: lines.map((line) => ({
            purchase_order_line_id: line.id,
            qty: '',
        })),
        notes: '',
    });

    const [pendingOvers, setPendingOvers] = useState([]);
    const [showConfirm, setShowConfirm] = useState(false);
    const formRef = useRef(null);

    const updateQty = (index, qty) => {
        const updated = data.lines.map((line, i) =>
            i === index ? { ...line, qty } : line,
        );
        setData('lines', updated);
    };

    // Bandingkan qty yang diketik terhadap sisa pesanan — ini murni untuk
    // menampilkan dialog konfirmasi lebih awal. Backend tetap menghitung
    // ulang dan menjadi otoritas final (lihat GoodsReceiptController::store).
    const findOverReceipts = () =>
        lines
            .map((line, index) => {
                const qty = Number(data.lines[index].qty || 0);
                const remaining = Number(line.remaining_qty_purchase_uom);

                if (qty <= 0 || qty <= remaining) return null;

                return {
                    line,
                    qty,
                    remaining,
                    ordered: Number(line.qty),
                    extreme: qty > Number(line.qty) * 2,
                };
            })
            .filter(Boolean);

    const doPost = (confirmed) => {
        transform((formData) => ({
            ...formData,
            confirm_overreceipt: confirmed,
        }));
        post(
            route(
                'pembelian.purchase-orders.receive.store',
                purchaseOrder.id,
            ),
        );
    };

    // Selalu tampilkan dialog konfirmasi dulu sebelum benar-benar menyimpan
    // (konsisten dengan form PO) — di situ juga tempat mengisi Catatan
    // opsional, supaya tidak mengganggu di bawah tabel qty.
    const submit = (e) => {
        e.preventDefault();
        setPendingOvers(findOverReceipts());
        setShowConfirm(true);
    };

    const confirmSave = () => {
        setShowConfirm(false);
        doPost(pendingOvers.length > 0);
    };

    // Enter di field biasa (qty, dll) TIDAK boleh langsung men-submit
    // penerimaan — ini destruktif (memicu jurnal & stok). Pola sama persis
    // dengan form PO: Enter pindah fokus ke field berikutnya, bukan submit.
    const handleFormKeyDown = (e) => {
        if (
            e.key !== 'Enter' ||
            e.ctrlKey ||
            e.defaultPrevented ||
            e.target.tagName === 'TEXTAREA'
        ) {
            return;
        }

        e.preventDefault();

        const focusable = Array.from(
            formRef.current?.querySelectorAll('input, select') ?? [],
        ).filter((el) => !el.disabled);
        const currentIndex = focusable.indexOf(e.target);
        if (currentIndex === -1) return;

        focusable[currentIndex + 1]?.focus();
    };

    // Ctrl+Enter simpan — konsisten dengan form PO.
    useEffect(() => {
        const handleKeyDown = (e) => {
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                formRef.current?.requestSubmit();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, []);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Terima Barang — PO-{purchaseOrder.id}
                </h2>
            }
        >
            <Head title={`Terima Barang PO-${purchaseOrder.id}`} />

            <div className="py-12">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div className="flex flex-wrap items-end gap-6">
                                <div className="max-w-xs">
                                    <InputLabel
                                        htmlFor="date"
                                        value="Tanggal Terima"
                                    />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        className="mt-1 h-10 block w-full"
                                        value={data.date}
                                        onChange={(e) =>
                                            setData('date', e.target.value)
                                        }
                                        required
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.date}
                                    />
                                </div>

                                <div>
                                    <InputLabel value="Metode Pembayaran" />
                                    <div className="mt-1 flex h-10 items-center gap-4">
                                        <label className="flex items-center gap-2 text-sm text-gray-700">
                                            <input
                                                type="radio"
                                                name="payment_method"
                                                checked={data.payment_method === 'credit'}
                                                onChange={() => setData('payment_method', 'credit')}
                                                className="text-primary focus:ring-primary"
                                            />
                                            Kredit (berhutang ke supplier)
                                        </label>
                                        <label className="flex items-center gap-2 text-sm text-gray-700">
                                            <input
                                                type="radio"
                                                name="payment_method"
                                                checked={data.payment_method === 'cash'}
                                                onChange={() => setData('payment_method', 'cash')}
                                                className="text-primary focus:ring-primary"
                                            />
                                            Tunai (dibayar sekarang)
                                        </label>
                                    </div>
                                    <InputError
                                        className="mt-2"
                                        message={errors.payment_method}
                                    />
                                </div>

                                {data.payment_method === 'cash' && (
                                    <div>
                                        <InputLabel
                                            htmlFor="cash_account_code"
                                            value="Dibayar Dari"
                                        />
                                        <SelectInput
                                            id="cash_account_code"
                                            className="mt-1 h-10 block w-full"
                                            value={data.cash_account_code}
                                            onChange={(e) =>
                                                setData(
                                                    'cash_account_code',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            {cashAccounts.map((account) => (
                                                <option
                                                    key={account.code}
                                                    value={account.code}
                                                >
                                                    {account.name}
                                                </option>
                                            ))}
                                        </SelectInput>
                                        <InputError
                                            className="mt-2"
                                            message={errors.cash_account_code}
                                        />
                                    </div>
                                )}
                            </div>

                            <p className="text-xs text-gray-400">
                                Pintasan: Ctrl+Enter simpan. Enter di
                                sebuah field pindah ke field berikutnya
                                (tidak menyimpan).
                            </p>

                            <InputError message={errors.lines} />

                            <table className="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Item
                                        </th>
                                        <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Dipesan
                                        </th>
                                        <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Sudah Diterima
                                        </th>
                                        <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Qty Diterima Sekarang
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {lines.map((line, index) => (
                                        <tr key={line.id}>
                                            <td className="px-2 py-3 text-sm text-gray-900">
                                                {line.item_sku} —{' '}
                                                {line.item_name}
                                            </td>
                                            <td className="px-2 py-3 text-sm text-gray-600">
                                                {formatDecimalID(line.qty)}{' '}
                                                {line.purchase_uom_code}
                                            </td>
                                            <td className="px-2 py-3 text-sm text-gray-600">
                                                {formatDecimalID(
                                                    line.received_qty_base_uom,
                                                )}{' '}
                                                {line.item_base_uom_code}
                                            </td>
                                            <td className="px-2 py-3">
                                                <NumberInput
                                                    className="w-32"
                                                    placeholder={`0 ${line.purchase_uom_code}`}
                                                    value={
                                                        data.lines[index]
                                                            .qty
                                                    }
                                                    onChange={(plain) =>
                                                        updateQty(
                                                            index,
                                                            plain,
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    className="mt-1"
                                                    message={
                                                        errors[
                                                            `lines.${index}.qty`
                                                        ]
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan Penerimaan{' '}
                                    <span className="ml-1 normal-case font-normal text-white/70">
                                        (Ctrl+Enter)
                                    </span>
                                </PrimaryButton>
                                <Link
                                    href={route(
                                        'pembelian.purchase-orders.show',
                                        purchaseOrder.id,
                                    )}
                                >
                                    <SecondaryButton type="button">
                                        Batal
                                    </SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <Modal show={showConfirm} onClose={() => setShowConfirm(false)} maxWidth="lg">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">
                        {pendingOvers.length > 0
                            ? 'Konfirmasi Kelebihan Penerimaan'
                            : 'Konfirmasi Simpan Penerimaan'}
                    </h3>

                    {pendingOvers.length > 0 && (
                        <div className="mt-4 space-y-3">
                            {pendingOvers.map((over) => (
                                <div
                                    key={over.line.id}
                                    className={`rounded-md border p-3 text-sm ${
                                        over.extreme
                                            ? 'border-red-400 bg-red-50'
                                            : 'border-amber-300 bg-amber-50'
                                    }`}
                                >
                                    <div className="font-medium text-gray-900">
                                        {over.line.item_sku} — {over.line.item_name}
                                    </div>
                                    <div
                                        className={
                                            over.extreme
                                                ? 'text-red-700'
                                                : 'text-amber-700'
                                        }
                                    >
                                        {over.extreme && (
                                            <div className="font-semibold">
                                                Kelebihan sangat besar — periksa kembali sebelum melanjutkan.
                                            </div>
                                        )}
                                        Anda menerima{' '}
                                        {formatDecimalID(over.qty)}, padahal
                                        dipesan {formatDecimalID(over.ordered)}{' '}
                                        (sisa {formatDecimalID(over.remaining)}){' '}
                                        {over.line.purchase_uom_code}. Kelebihan
                                        ini akan menambah stok.
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="mt-4">
                        <InputLabel
                            htmlFor="notes"
                            value="Catatan (opsional)"
                        />
                        <textarea
                            id="notes"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                            rows={3}
                            placeholder='mis. "barang sedikit penyok"'
                            value={data.notes}
                            onChange={(e) =>
                                setData('notes', e.target.value)
                            }
                        />
                        <InputError
                            className="mt-2"
                            message={errors.notes}
                        />
                    </div>

                    <p className="mt-4 text-sm text-gray-600">
                        {pendingOvers.length > 0
                            ? 'Yakin ingin melanjutkan penerimaan ini?'
                            : 'Yakin ingin menyimpan penerimaan ini?'}
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setShowConfirm(false)}>
                            Batal, Periksa Lagi
                        </SecondaryButton>
                        {pendingOvers.length > 0 ? (
                            <DangerButton onClick={confirmSave}>
                                Ya, Tetap Terima
                            </DangerButton>
                        ) : (
                            <PrimaryButton
                                disabled={processing}
                                onClick={confirmSave}
                            >
                                Ya, Simpan
                            </PrimaryButton>
                        )}
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
