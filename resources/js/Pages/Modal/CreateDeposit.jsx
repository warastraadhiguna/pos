import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value || 0)).toLocaleString('id-ID');

export default function CreateDeposit({ outlets, cashAccounts }) {
    const { data, setData, post, processing, errors } = useForm({
        outlet_id: outlets[0]?.id ?? '',
        date: new Date().toISOString().slice(0, 10),
        amount: '',
        cash_account_code: cashAccounts[0]?.code ?? '',
        description: '',
    });

    const [showConfirm, setShowConfirm] = useState(false);
    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();
        setShowConfirm(true);
    };

    const confirmSave = () => {
        setShowConfirm(false);
        post(route('modal.deposit.store'));
    };

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
                    Catat Setoran Modal
                </h2>
            }
        >
            <Head title="Catat Setoran Modal" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="outlet_id" value="Outlet" />
                                    <SelectInput
                                        id="outlet_id"
                                        className="mt-1 h-10 block w-full"
                                        value={data.outlet_id}
                                        onChange={(e) => setData('outlet_id', e.target.value)}
                                        required
                                    >
                                        {outlets.map((outlet) => (
                                            <option key={outlet.id} value={outlet.id}>
                                                {outlet.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError className="mt-2" message={errors.outlet_id} />
                                </div>

                                <div>
                                    <InputLabel htmlFor="date" value="Tanggal" />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        className="mt-1 h-10 block w-full"
                                        value={data.date}
                                        onChange={(e) => setData('date', e.target.value)}
                                        required
                                    />
                                    <InputError className="mt-2" message={errors.date} />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="amount" value="Jumlah" />
                                    <NumberInput
                                        id="amount"
                                        className="mt-1 h-10 block w-full"
                                        maxDecimals={0}
                                        placeholder="0"
                                        value={data.amount}
                                        onChange={(plain) => setData('amount', plain)}
                                        required
                                    />
                                    <InputError className="mt-2" message={errors.amount} />
                                </div>

                                <div>
                                    <InputLabel htmlFor="cash_account_code" value="Masuk Ke" />
                                    <SelectInput
                                        id="cash_account_code"
                                        className="mt-1 h-10 block w-full"
                                        value={data.cash_account_code}
                                        onChange={(e) => setData('cash_account_code', e.target.value)}
                                        required
                                    >
                                        {cashAccounts.map((account) => (
                                            <option key={account.code} value={account.code}>
                                                {account.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError className="mt-2" message={errors.cash_account_code} />
                                </div>
                            </div>

                            <div>
                                <InputLabel htmlFor="description" value="Keterangan (opsional)" />
                                <TextInput
                                    id="description"
                                    type="text"
                                    className="mt-1 block w-full"
                                    placeholder='mis. "Tambahan modal usaha"'
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                />
                                <InputError className="mt-2" message={errors.description} />
                            </div>

                            <p className="text-xs text-gray-400">
                                Pintasan: Ctrl+Enter simpan. Enter di sebuah
                                field pindah ke field berikutnya (tidak
                                menyimpan).
                            </p>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan Setoran{' '}
                                    <span className="ml-1 normal-case font-normal text-white/70">
                                        (Ctrl+Enter)
                                    </span>
                                </PrimaryButton>
                                <Link href={route('modal.index')}>
                                    <SecondaryButton type="button">Batal</SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <Modal show={showConfirm} onClose={() => setShowConfirm(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">
                        Konfirmasi Setoran Modal
                    </h3>
                    <div className="mt-4 space-y-1 rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                        <div className="flex justify-between">
                            <span>Jumlah</span>
                            <span className="font-medium">{formatRupiah(data.amount)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span>Masuk Ke</span>
                            <span className="font-medium">
                                {cashAccounts.find((a) => a.code === data.cash_account_code)?.name ?? '-'}
                            </span>
                        </div>
                    </div>
                    <p className="mt-4 text-sm text-gray-600">
                        Yakin ingin menyimpan setoran modal ini?
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setShowConfirm(false)}>
                            Batal, Periksa Lagi
                        </SecondaryButton>
                        <PrimaryButton disabled={processing} onClick={confirmSave}>
                            Ya, Simpan
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
