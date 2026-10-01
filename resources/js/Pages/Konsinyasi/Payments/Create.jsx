import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SupplierCombobox from '@/Components/SupplierCombobox';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

const statusLabel = { lunas: 'Lunas', sebagian: 'Sebagian', belum: 'Belum Dibayar' };
const statusClass = {
    lunas: 'text-green-700',
    sebagian: 'text-amber-600',
    belum: 'text-red-600',
};

/**
 * "Bayar Hutang Konsinyasi" -- pola form PERSIS
 * Pembelian/SupplierPayments/Create.jsx, cuma "nota" di sini adalah
 * ConsignmentAccrual (satu per Penjualan x supplier), bukan GoodsReceipt.
 */
export default function Create({ outlets, initialSupplier, cashAccounts }) {
    const { data, setData, post, processing, errors } = useForm({
        outlet_id: outlets[0]?.id ?? '',
        supplier_id: initialSupplier?.id ?? '',
        date: new Date().toISOString().slice(0, 10),
        amount: '',
        cash_account_code: cashAccounts[0]?.code ?? '',
        memo: '',
        allocations: [],
    });

    const [supplierItem, setSupplierItem] = useState(initialSupplier ?? null);
    const [outstanding, setOutstanding] = useState(null);
    const [accruals, setAccruals] = useState([]);
    const [loadingAccruals, setLoadingAccruals] = useState(false);
    const [mode, setMode] = useState('fifo');
    const [fifoAmount, setFifoAmount] = useState('');
    const [fifoAllocations, setFifoAllocations] = useState([]);
    const [loadingFifo, setLoadingFifo] = useState(false);
    // consignment_accrual_id (atau 'advance' untuk uang muka) -> jumlah
    // yang diketik user di mode manual.
    const [manualAmounts, setManualAmounts] = useState({});

    const fifoDebounce = useRef(null);
    const formRef = useRef(null);

    const loadAccruals = async (supplierId) => {
        setLoadingAccruals(true);
        try {
            const response = await axios.get(
                route('konsinyasi.payments.summary'),
                { params: { supplier_id: supplierId } },
            );
            setOutstanding(response.data.outstanding);
            setAccruals(response.data.accruals);
            return response.data.accruals;
        } finally {
            setLoadingAccruals(false);
        }
    };

    useEffect(() => {
        if (initialSupplier) {
            loadAccruals(initialSupplier.id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const selectSupplier = async (supplier) => {
        setSupplierItem(supplier);
        setData('supplier_id', supplier.id);
        setOutstanding(null);
        setAccruals([]);
        setFifoAmount('');
        setFifoAllocations([]);
        setManualAmounts({});
        await loadAccruals(supplier.id);
    };

    const unpaidAccruals = accruals.filter((accrual) => accrual.status !== 'lunas');

    const updateFifoAmount = (value) => {
        setFifoAmount(value);
        clearTimeout(fifoDebounce.current);

        if (!supplierItem || !value || Number(value) <= 0) {
            setFifoAllocations([]);
            return;
        }

        fifoDebounce.current = setTimeout(async () => {
            setLoadingFifo(true);
            try {
                const response = await axios.get(
                    route('konsinyasi.payments.fifo-preview'),
                    { params: { supplier_id: supplierItem.id, amount: value } },
                );
                setFifoAllocations(response.data.allocations);
            } finally {
                setLoadingFifo(false);
            }
        }, 300);
    };

    const toggleManualAccrual = (accrual, checked) => {
        setManualAmounts((previous) => {
            const next = { ...previous };
            if (checked) {
                next[accrual.consignment_accrual_id] = accrual.remaining;
            } else {
                delete next[accrual.consignment_accrual_id];
            }
            return next;
        });
    };

    const updateManualAmount = (key, value) => {
        setManualAmounts((previous) => ({ ...previous, [key]: value }));
    };

    const manualTotal = Object.values(manualAmounts).reduce(
        (sum, value) => sum + (Number(value) || 0),
        0,
    );

    useEffect(() => {
        if (mode === 'fifo') {
            setData('amount', fifoAmount);
            setData('allocations', fifoAllocations);
        } else {
            const allocations = Object.entries(manualAmounts)
                .filter(([, amount]) => Number(amount) > 0)
                .map(([key, amount]) => ({
                    consignment_accrual_id: key === 'advance' ? null : Number(key),
                    amount,
                }));
            setData('amount', String(manualTotal));
            setData('allocations', allocations);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mode, fifoAmount, fifoAllocations, manualAmounts]);

    const switchMode = (newMode) => {
        setMode(newMode);
        setFifoAmount('');
        setFifoAllocations([]);
        setManualAmounts({});
    };

    const [showConfirmSave, setShowConfirmSave] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        setShowConfirmSave(true);
    };

    const confirmSave = () => {
        setShowConfirmSave(false);
        post(route('konsinyasi.payments.store'));
    };

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

    const fifoPreviewRows = fifoAllocations.map((allocation) => {
        if (allocation.consignment_accrual_id === null) {
            return { advance: true, amount: allocation.amount };
        }
        const accrual = accruals.find(
            (a) => a.consignment_accrual_id === allocation.consignment_accrual_id,
        );
        return {
            ...accrual,
            allocated_now: allocation.amount,
            remaining_after: String(
                Number(accrual?.remaining ?? 0) - Number(allocation.amount),
            ),
        };
    });

    const canSubmit = data.allocations.length > 0 && Number(data.amount) > 0;

    const confirmAllocationRows = data.allocations.map((allocation) => {
        if (allocation.consignment_accrual_id === null) {
            return { advance: true, amount: allocation.amount };
        }
        const accrual = accruals.find(
            (a) => a.consignment_accrual_id === allocation.consignment_accrual_id,
        );
        return { ...accrual, allocated_now: allocation.amount };
    });

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Bayar Hutang Konsinyasi
                </h2>
            }
        >
            <Head title="Bayar Hutang Konsinyasi" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div>
                                <InputLabel value="Supplier" />
                                <div className="mt-1">
                                    <SupplierCombobox
                                        initialItem={supplierItem}
                                        onSelect={selectSupplier}
                                    />
                                </div>
                                <InputError
                                    className="mt-2"
                                    message={errors.supplier_id}
                                />
                            </div>

                            {supplierItem && (
                                <div className="rounded-md bg-gray-50 p-4 text-sm">
                                    {loadingAccruals ? (
                                        <p className="text-gray-500">
                                            Memuat sisa hutang...
                                        </p>
                                    ) : (
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">
                                                Sisa hutang konsinyasi saat ini ke{' '}
                                                {supplierItem.name}
                                            </span>
                                            <span className="font-semibold text-gray-900">
                                                {formatRupiah(
                                                    outstanding ?? 0,
                                                )}
                                            </span>
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel
                                        htmlFor="outlet_id"
                                        value="Outlet"
                                    />
                                    <SelectInput
                                        id="outlet_id"
                                        className="mt-1 block w-full"
                                        value={data.outlet_id}
                                        onChange={(e) =>
                                            setData(
                                                'outlet_id',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    >
                                        {outlets.map((outlet) => (
                                            <option
                                                key={outlet.id}
                                                value={outlet.id}
                                            >
                                                {outlet.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError
                                        className="mt-2"
                                        message={errors.outlet_id}
                                    />
                                </div>

                                <div>
                                    <InputLabel
                                        htmlFor="date"
                                        value="Tanggal Bayar"
                                    />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        className="mt-1 block w-full"
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
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="cash_account_code"
                                    value="Dibayar Dari"
                                />
                                <SelectInput
                                    id="cash_account_code"
                                    className="mt-1 h-10 block w-full sm:w-64"
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

                            <div>
                                <InputLabel value="Cara Alokasi" />
                                <div className="mt-1 flex gap-2">
                                    <SecondaryButton
                                        type="button"
                                        className={
                                            mode === 'fifo'
                                                ? 'ring-2 ring-primary'
                                                : ''
                                        }
                                        onClick={() => switchMode('fifo')}
                                    >
                                        Otomatis (FIFO)
                                    </SecondaryButton>
                                    <SecondaryButton
                                        type="button"
                                        className={
                                            mode === 'manual'
                                                ? 'ring-2 ring-primary'
                                                : ''
                                        }
                                        onClick={() => switchMode('manual')}
                                    >
                                        Manual per Transaksi
                                    </SecondaryButton>
                                </div>
                                <p className="mt-1 text-xs text-gray-400">
                                    Otomatis: masukkan jumlah, sistem
                                    alokasikan ke transaksi tertua dulu.
                                    Manual: pilih & atur sendiri jumlah per
                                    transaksi.
                                </p>
                            </div>

                            {mode === 'fifo' ? (
                                <div>
                                    <InputLabel
                                        htmlFor="fifo_amount"
                                        value="Jumlah Dibayar"
                                    />
                                    <NumberInput
                                        id="fifo_amount"
                                        className="block w-full"
                                        placeholder="0"
                                        value={fifoAmount}
                                        onChange={updateFifoAmount}
                                        required
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.amount}
                                    />

                                    {loadingFifo && (
                                        <p className="mt-2 text-sm text-gray-500">
                                            Menghitung alokasi...
                                        </p>
                                    )}

                                    {!loadingFifo &&
                                        fifoPreviewRows.length > 0 && (
                                            <div className="mt-3 space-y-2">
                                                <p className="text-sm font-medium text-gray-700">
                                                    Pratinjau alokasi:
                                                </p>
                                                {fifoPreviewRows.map(
                                                    (row, index) =>
                                                        row.advance ? (
                                                            <div
                                                                key="advance"
                                                                className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800"
                                                            >
                                                                Uang muka /
                                                                belum
                                                                teralokasi ke
                                                                transaksi
                                                                manapun:{' '}
                                                                <span className="font-semibold">
                                                                    {formatRupiah(
                                                                        row.amount,
                                                                    )}
                                                                </span>
                                                            </div>
                                                        ) : (
                                                            <div
                                                                key={
                                                                    row.consignment_accrual_id ??
                                                                    index
                                                                }
                                                                className="rounded-md border border-gray-200 p-3 text-sm"
                                                            >
                                                                <div className="font-medium text-gray-900">
                                                                    Penjualan #
                                                                    {
                                                                        row.sale_id
                                                                    }{' '}
                                                                    —{' '}
                                                                    {formatDate(
                                                                        row.date,
                                                                    )}
                                                                </div>
                                                                <div className="text-gray-600">
                                                                    Sisa
                                                                    sebelum:{' '}
                                                                    {formatRupiah(
                                                                        row.remaining,
                                                                    )}{' '}
                                                                    → Dialokasikan:{' '}
                                                                    <span className="font-semibold text-gray-900">
                                                                        {formatRupiah(
                                                                            row.allocated_now,
                                                                        )}
                                                                    </span>{' '}
                                                                    → Sisa
                                                                    sesudah:{' '}
                                                                    {formatRupiah(
                                                                        row.remaining_after,
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ),
                                                )}
                                            </div>
                                        )}
                                </div>
                            ) : (
                                <div>
                                    <InputLabel value="Alokasi per Transaksi" />
                                    {unpaidAccruals.length === 0 && (
                                        <p className="mt-1 text-sm text-gray-500">
                                            Tidak ada transaksi konsinyasi
                                            yang belum lunas untuk supplier
                                            ini.
                                        </p>
                                    )}
                                    <div className="mt-2 space-y-2">
                                        {unpaidAccruals.map((accrual) => {
                                            const checked =
                                                manualAmounts[
                                                    accrual.consignment_accrual_id
                                                ] !== undefined;
                                            return (
                                                <div
                                                    key={
                                                        accrual.consignment_accrual_id
                                                    }
                                                    className="flex items-center gap-3 rounded-md border border-gray-200 p-3"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={(e) =>
                                                            toggleManualAccrual(
                                                                accrual,
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                        className="text-primary focus:ring-primary"
                                                    />
                                                    <div className="flex-1 text-sm">
                                                        <div className="font-medium text-gray-900">
                                                            Penjualan #
                                                            {
                                                                accrual.sale_id
                                                            }{' '}
                                                            —{' '}
                                                            {formatDate(
                                                                accrual.date,
                                                            )}
                                                        </div>
                                                        <div
                                                            className={
                                                                statusClass[
                                                                    accrual
                                                                        .status
                                                                ]
                                                            }
                                                        >
                                                            {
                                                                statusLabel[
                                                                    accrual
                                                                        .status
                                                                ]
                                                            }{' '}
                                                            — sisa{' '}
                                                            {formatRupiah(
                                                                accrual.remaining,
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="w-36">
                                                        <NumberInput
                                                            className="h-10 block w-full"
                                                            placeholder="0"
                                                            value={
                                                                manualAmounts[
                                                                    accrual
                                                                        .consignment_accrual_id
                                                                ] ?? ''
                                                            }
                                                            onChange={(v) =>
                                                                updateManualAmount(
                                                                    accrual.consignment_accrual_id,
                                                                    v,
                                                                )
                                                            }
                                                            disabled={
                                                                !checked
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        })}

                                        <div className="flex items-center gap-3 rounded-md border border-amber-300 bg-amber-50 p-3">
                                            <div className="flex-1 text-sm text-amber-800">
                                                Uang Muka / Belum Teralokasi
                                                ke Transaksi Manapun
                                            </div>
                                            <div className="w-36">
                                                <NumberInput
                                                    className="h-10 block w-full"
                                                    placeholder="0"
                                                    value={
                                                        manualAmounts[
                                                            'advance'
                                                        ] ?? ''
                                                    }
                                                    onChange={(v) =>
                                                        updateManualAmount(
                                                            'advance',
                                                            v,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-3 flex justify-between border-t border-gray-200 pt-2 text-sm font-semibold text-gray-900">
                                        <span>Total Dibayar</span>
                                        <span>
                                            {formatRupiah(manualTotal)}
                                        </span>
                                    </div>
                                    <InputError
                                        className="mt-2"
                                        message={errors.amount}
                                    />
                                </div>
                            )}

                            <InputError message={errors.allocations} />

                            <div>
                                <InputLabel
                                    htmlFor="memo"
                                    value="Catatan (opsional)"
                                />
                                <TextInput
                                    id="memo"
                                    className="mt-1 block w-full"
                                    value={data.memo}
                                    onChange={(e) =>
                                        setData('memo', e.target.value)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.memo}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton
                                    disabled={processing || !canSubmit}
                                >
                                    Simpan Pembayaran
                                </PrimaryButton>
                                <Link
                                    href={route(
                                        'konsinyasi.payments.index',
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

            <Modal
                show={showConfirmSave}
                onClose={() => setShowConfirmSave(false)}
                maxWidth="lg"
            >
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">
                        Konfirmasi Pembayaran
                    </h3>
                    <div className="mt-4 space-y-2 text-sm text-gray-700">
                        <div className="flex justify-between">
                            <span>Supplier</span>
                            <span className="font-medium">
                                {supplierItem?.name ?? '-'}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span>Tanggal Bayar</span>
                            <span className="font-medium">
                                {formatDate(data.date)}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span>Dibayar Dari</span>
                            <span className="font-medium">
                                {cashAccounts.find((a) => a.code === data.cash_account_code)?.name ?? '-'}
                            </span>
                        </div>
                        <div className="flex justify-between border-t border-gray-200 pt-2 font-semibold text-gray-900">
                            <span>Jumlah Dibayar</span>
                            <span>{formatRupiah(data.amount)}</span>
                        </div>
                    </div>

                    {confirmAllocationRows.length > 0 && (
                        <div className="mt-4 space-y-2">
                            <p className="text-sm font-medium text-gray-700">
                                Alokasi:
                            </p>
                            {confirmAllocationRows.map((row, index) =>
                                row.advance ? (
                                    <div
                                        key="advance"
                                        className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800"
                                    >
                                        Uang muka / belum teralokasi ke
                                        transaksi manapun:{' '}
                                        <span className="font-semibold">
                                            {formatRupiah(row.amount)}
                                        </span>
                                    </div>
                                ) : (
                                    <div
                                        key={row.consignment_accrual_id ?? index}
                                        className="rounded-md border border-gray-200 p-3 text-sm"
                                    >
                                        <div className="font-medium text-gray-900">
                                            Penjualan #{row.sale_id} —{' '}
                                            {formatDate(row.date)}
                                        </div>
                                        <div className="text-gray-600">
                                            Dialokasikan:{' '}
                                            <span className="font-semibold text-gray-900">
                                                {formatRupiah(
                                                    row.allocated_now,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>
                    )}

                    <p className="mt-4 text-sm text-gray-600">
                        Setelah disimpan, pembayaran ini tercatat sebagai
                        transaksi keuangan (jurnal Kas & Hutang Konsinyasi).
                        Periksa sekali lagi sebelum melanjutkan.
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton
                            onClick={() => setShowConfirmSave(false)}
                        >
                            Periksa Lagi
                        </SecondaryButton>
                        <PrimaryButton
                            disabled={processing}
                            onClick={confirmSave}
                        >
                            Ya, Simpan Pembayaran
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
