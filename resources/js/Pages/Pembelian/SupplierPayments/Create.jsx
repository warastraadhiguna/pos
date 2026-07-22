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

export default function Create({ outlets, initialSupplier, initialGoodsReceiptId, cashAccounts }) {
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
    const [notas, setNotas] = useState([]);
    const [loadingNotas, setLoadingNotas] = useState(false);
    const [mode, setMode] = useState('fifo');
    const [fifoAmount, setFifoAmount] = useState('');
    const [fifoAllocations, setFifoAllocations] = useState([]);
    const [loadingFifo, setLoadingFifo] = useState(false);
    // goods_receipt_id (atau 'advance' untuk uang muka) -> jumlah yang
    // diketik user di mode manual.
    const [manualAmounts, setManualAmounts] = useState({});

    // Query ?supplier_id=&goods_receipt_id= (dari tautan cepat "Bayar" di
    // halaman PO) HANYA titik awal — dipakai sekali saat halaman dibuka.
    // Tidak ada apa pun sesudah ini yang menyinkron ulang dari prop
    // tersebut, jadi pindah ke FIFO atau mengubah centang manual tidak
    // pernah "nyangkut" balik ke nilai awal ini.
    const appliedInitialGoodsReceipt = useRef(false);
    const fifoDebounce = useRef(null);
    const formRef = useRef(null);

    const loadNotas = async (supplierId) => {
        setLoadingNotas(true);
        try {
            const response = await axios.get(
                route('pembelian.supplier-payments.summary'),
                { params: { supplier_id: supplierId } },
            );
            setOutstanding(response.data.outstanding);
            setNotas(response.data.notas);
            return response.data.notas;
        } finally {
            setLoadingNotas(false);
        }
    };

    useEffect(() => {
        if (initialSupplier) {
            loadNotas(initialSupplier.id).then((loadedNotas) => {
                if (
                    initialGoodsReceiptId &&
                    !appliedInitialGoodsReceipt.current
                ) {
                    appliedInitialGoodsReceipt.current = true;
                    const nota = loadedNotas.find(
                        (n) => n.goods_receipt_id === initialGoodsReceiptId,
                    );
                    if (nota) {
                        setMode('manual');
                        setManualAmounts({
                            [nota.goods_receipt_id]: nota.remaining,
                        });
                    }
                }
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const selectSupplier = async (supplier) => {
        setSupplierItem(supplier);
        setData('supplier_id', supplier.id);
        setOutstanding(null);
        setNotas([]);
        setFifoAmount('');
        setFifoAllocations([]);
        setManualAmounts({});
        await loadNotas(supplier.id);
    };

    const unpaidNotas = notas.filter((nota) => nota.status !== 'lunas');

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
                    route('pembelian.supplier-payments.fifo-preview'),
                    { params: { supplier_id: supplierItem.id, amount: value } },
                );
                setFifoAllocations(response.data.allocations);
            } finally {
                setLoadingFifo(false);
            }
        }, 300);
    };

    const toggleManualNota = (nota, checked) => {
        setManualAmounts((previous) => {
            const next = { ...previous };
            if (checked) {
                next[nota.goods_receipt_id] = nota.remaining;
            } else {
                delete next[nota.goods_receipt_id];
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

    // amount & allocations yang benar-benar dikirim selalu DITURUNKAN dari
    // mode yang sedang aktif — bukan dua state independen yang bisa saling
    // menyimpang.
    useEffect(() => {
        if (mode === 'fifo') {
            setData('amount', fifoAmount);
            setData('allocations', fifoAllocations);
        } else {
            const allocations = Object.entries(manualAmounts)
                .filter(([, amount]) => Number(amount) > 0)
                .map(([key, amount]) => ({
                    goods_receipt_id: key === 'advance' ? null : Number(key),
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

    // Jangan langsung simpan — tampilkan ringkasan pembayaran & alokasinya
    // dulu (pola sama dengan form PO/Penerimaan Barang) supaya user bisa
    // memeriksa sebelum transaksi keuangan ini benar-benar tercatat.
    const submit = (e) => {
        e.preventDefault();
        setShowConfirmSave(true);
    };

    const confirmSave = () => {
        setShowConfirmSave(false);
        post(route('pembelian.supplier-payments.store'));
    };

    // Enter di field jumlah/alokasi TIDAK boleh langsung mencatat pembayaran
    // (ini transaksi keuangan nyata) — pola sama dengan form Pembelian
    // lainnya: Enter pindah ke field berikutnya, bukan submit. Enter di
    // dalam SupplierCombobox tetap berarti "pilih hasil highlight" (combobox
    // sudah preventDefault sendiri, dihormati lewat e.defaultPrevented).
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
        if (allocation.goods_receipt_id === null) {
            return { advance: true, amount: allocation.amount };
        }
        const nota = notas.find(
            (n) => n.goods_receipt_id === allocation.goods_receipt_id,
        );
        return {
            ...nota,
            allocated_now: allocation.amount,
            // Pratinjau tampilan saja (tidak dikirim/disimpan) — angka final
            // tetap dihitung ulang di server dengan bcmath saat submit.
            remaining_after: String(
                Number(nota?.remaining ?? 0) - Number(allocation.amount),
            ),
        };
    });

    const canSubmit = data.allocations.length > 0 && Number(data.amount) > 0;

    // Ringkasan untuk dialog konfirmasi — diturunkan dari data.allocations
    // yang sama-sama dikirim ke server, jadi selalu sinkron dengan apa yang
    // benar-benar akan tersimpan (baik mode FIFO maupun manual).
    const confirmAllocationRows = data.allocations.map((allocation) => {
        if (allocation.goods_receipt_id === null) {
            return { advance: true, amount: allocation.amount };
        }
        const nota = notas.find(
            (n) => n.goods_receipt_id === allocation.goods_receipt_id,
        );
        return { ...nota, allocated_now: allocation.amount };
    });

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Bayar Hutang Supplier
                </h2>
            }
        >
            <Head title="Bayar Hutang Supplier" />

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
                                    {loadingNotas ? (
                                        <p className="text-gray-500">
                                            Memuat sisa hutang...
                                        </p>
                                    ) : (
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">
                                                Sisa hutang saat ini ke{' '}
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
                                        Manual per Nota
                                    </SecondaryButton>
                                </div>
                                <p className="mt-1 text-xs text-gray-400">
                                    Otomatis: masukkan jumlah, sistem
                                    alokasikan ke nota tertua dulu. Manual:
                                    pilih & atur sendiri jumlah per nota.
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
                                                                nota manapun:{' '}
                                                                <span className="font-semibold">
                                                                    {formatRupiah(
                                                                        row.amount,
                                                                    )}
                                                                </span>
                                                            </div>
                                                        ) : (
                                                            <div
                                                                key={
                                                                    row.goods_receipt_id ??
                                                                    index
                                                                }
                                                                className="rounded-md border border-gray-200 p-3 text-sm"
                                                            >
                                                                <div className="font-medium text-gray-900">
                                                                    Nota #
                                                                    {
                                                                        row.goods_receipt_id
                                                                    }{' '}
                                                                    (PO-
                                                                    {
                                                                        row.purchase_order_id
                                                                    }
                                                                    ) —{' '}
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
                                    <InputLabel value="Alokasi per Nota" />
                                    {unpaidNotas.length === 0 && (
                                        <p className="mt-1 text-sm text-gray-500">
                                            Tidak ada nota kredit yang belum
                                            lunas untuk supplier ini.
                                        </p>
                                    )}
                                    <div className="mt-2 space-y-2">
                                        {unpaidNotas.map((nota) => {
                                            const checked =
                                                manualAmounts[
                                                    nota.goods_receipt_id
                                                ] !== undefined;
                                            return (
                                                <div
                                                    key={nota.goods_receipt_id}
                                                    className="flex items-center gap-3 rounded-md border border-gray-200 p-3"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={(e) =>
                                                            toggleManualNota(
                                                                nota,
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                        className="text-primary focus:ring-primary"
                                                    />
                                                    <div className="flex-1 text-sm">
                                                        <div className="font-medium text-gray-900">
                                                            Nota #
                                                            {
                                                                nota.goods_receipt_id
                                                            }{' '}
                                                            (PO-
                                                            {
                                                                nota.purchase_order_id
                                                            }
                                                            ) —{' '}
                                                            {formatDate(
                                                                nota.date,
                                                            )}
                                                        </div>
                                                        <div
                                                            className={
                                                                statusClass[
                                                                    nota
                                                                        .status
                                                                ]
                                                            }
                                                        >
                                                            {
                                                                statusLabel[
                                                                    nota
                                                                        .status
                                                                ]
                                                            }{' '}
                                                            — sisa{' '}
                                                            {formatRupiah(
                                                                nota.remaining,
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="w-36">
                                                        <NumberInput
                                                            className="h-10 block w-full"
                                                            placeholder="0"
                                                            value={
                                                                manualAmounts[
                                                                    nota
                                                                        .goods_receipt_id
                                                                ] ?? ''
                                                            }
                                                            onChange={(v) =>
                                                                updateManualAmount(
                                                                    nota.goods_receipt_id,
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
                                                ke Nota Manapun
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
                                        'pembelian.supplier-payments.index',
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
                                        nota manapun:{' '}
                                        <span className="font-semibold">
                                            {formatRupiah(row.amount)}
                                        </span>
                                    </div>
                                ) : (
                                    <div
                                        key={row.goods_receipt_id ?? index}
                                        className="rounded-md border border-gray-200 p-3 text-sm"
                                    >
                                        <div className="font-medium text-gray-900">
                                            Nota #{row.goods_receipt_id}{' '}
                                            (PO-{row.purchase_order_id}) —{' '}
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
                        transaksi keuangan (jurnal Kas & Hutang Usaha).
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
