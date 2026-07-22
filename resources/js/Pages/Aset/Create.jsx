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

export default function Create({ outlets, cashAccounts }) {
    const { data, setData, post, processing, errors } = useForm({
        outlet_id: outlets[0]?.id ?? '',
        name: '',
        category: '',
        purchase_date: new Date().toISOString().slice(0, 10),
        acquisition_cost: '',
        residual_value: '0',
        useful_life_years: '4',
        // Default 'cash' -- pembelian aset toko kecil biasanya dibayar
        // langsung; "Kredit" adalah pilihan sadar.
        payment_method: 'cash',
        cash_account_code: cashAccounts[0]?.code ?? '',
    });

    const [showConfirm, setShowConfirm] = useState(false);
    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();
        setShowConfirm(true);
    };

    const confirmSave = () => {
        setShowConfirm(false);
        post(route('aset.store'));
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

    const monthlyEstimate = (() => {
        const cost = Number(data.acquisition_cost || 0);
        const residual = Number(data.residual_value || 0);
        const months = Number(data.useful_life_years || 0) * 12;
        if (cost <= 0 || months <= 0 || residual >= cost) return null;
        return (cost - residual) / months;
    })();

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Catat Aset Baru
                </h2>
            }
        >
            <Head title="Catat Aset Baru" />

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
                                    <InputLabel htmlFor="purchase_date" value="Tanggal Beli" />
                                    <TextInput
                                        id="purchase_date"
                                        type="date"
                                        className="mt-1 h-10 block w-full"
                                        value={data.purchase_date}
                                        onChange={(e) => setData('purchase_date', e.target.value)}
                                        required
                                    />
                                    <InputError className="mt-2" message={errors.purchase_date} />
                                </div>
                            </div>

                            <div>
                                <InputLabel htmlFor="name" value="Nama Aset" />
                                <TextInput
                                    id="name"
                                    type="text"
                                    className="mt-1 block w-full"
                                    placeholder='mis. "Kulkas Sanken 2 Pintu"'
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                <InputError className="mt-2" message={errors.name} />
                            </div>

                            <div>
                                <InputLabel htmlFor="category" value="Kategori (opsional)" />
                                <TextInput
                                    id="category"
                                    type="text"
                                    className="mt-1 block w-full"
                                    placeholder='mis. "Peralatan", "Kendaraan"'
                                    value={data.category}
                                    onChange={(e) => setData('category', e.target.value)}
                                />
                                <InputError className="mt-2" message={errors.category} />
                            </div>

                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                                <div>
                                    <InputLabel htmlFor="acquisition_cost" value="Harga Perolehan" />
                                    <NumberInput
                                        id="acquisition_cost"
                                        className="mt-1 h-10 block w-full"
                                        maxDecimals={0}
                                        placeholder="0"
                                        value={data.acquisition_cost}
                                        onChange={(plain) => setData('acquisition_cost', plain)}
                                        required
                                    />
                                    <InputError className="mt-2" message={errors.acquisition_cost} />
                                </div>

                                <div>
                                    <InputLabel htmlFor="residual_value" value="Nilai Residu (opsional)" />
                                    <NumberInput
                                        id="residual_value"
                                        className="mt-1 h-10 block w-full"
                                        maxDecimals={0}
                                        placeholder="0"
                                        value={data.residual_value}
                                        onChange={(plain) => setData('residual_value', plain)}
                                    />
                                    <InputError className="mt-2" message={errors.residual_value} />
                                </div>

                                <div>
                                    <InputLabel htmlFor="useful_life_years" value="Masa Manfaat (tahun)" />
                                    <TextInput
                                        id="useful_life_years"
                                        type="number"
                                        min="1"
                                        step="1"
                                        className="mt-1 h-10 block w-full"
                                        value={data.useful_life_years}
                                        onChange={(e) => setData('useful_life_years', e.target.value)}
                                        required
                                    />
                                    <InputError className="mt-2" message={errors.useful_life_years} />
                                </div>
                            </div>

                            {monthlyEstimate !== null && (
                                <p className="text-xs text-gray-500">
                                    Perkiraan penyusutan garis lurus: sekitar{' '}
                                    <span className="font-medium">{formatRupiah(monthlyEstimate)}</span>{' '}
                                    per bulan.
                                </p>
                            )}

                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <div>
                                    <InputLabel value="Metode Pembayaran" />
                                    <div className="mt-1 flex h-10 items-center gap-4">
                                        <label className="flex items-center gap-2 text-sm text-gray-700">
                                            <input
                                                type="radio"
                                                name="payment_method"
                                                checked={data.payment_method === 'cash'}
                                                onChange={() => setData('payment_method', 'cash')}
                                                className="text-primary focus:ring-primary"
                                            />
                                            Tunai
                                        </label>
                                        <label className="flex items-center gap-2 text-sm text-gray-700">
                                            <input
                                                type="radio"
                                                name="payment_method"
                                                checked={data.payment_method === 'credit'}
                                                onChange={() => setData('payment_method', 'credit')}
                                                className="text-primary focus:ring-primary"
                                            />
                                            Kredit (dibayar belakangan)
                                        </label>
                                    </div>
                                    <InputError className="mt-2" message={errors.payment_method} />
                                </div>

                                {data.payment_method === 'cash' && (
                                    <div>
                                        <InputLabel htmlFor="cash_account_code" value="Dibayar Dari" />
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
                                )}
                            </div>

                            <p className="text-xs text-gray-400">
                                Pintasan: Ctrl+Enter simpan. Enter di sebuah
                                field pindah ke field berikutnya (tidak
                                menyimpan).
                            </p>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan Aset{' '}
                                    <span className="ml-1 normal-case font-normal text-white/70">
                                        (Ctrl+Enter)
                                    </span>
                                </PrimaryButton>
                                <Link href={route('aset.index')}>
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
                        Konfirmasi Simpan Aset
                    </h3>
                    <div className="mt-4 space-y-1 rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                        <div className="flex justify-between">
                            <span>Nama</span>
                            <span className="font-medium">{data.name || '-'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span>Harga Perolehan</span>
                            <span className="font-medium">{formatRupiah(data.acquisition_cost)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span>Metode</span>
                            <span className="font-medium">
                                {data.payment_method === 'cash' ? 'Tunai' : 'Kredit'}
                            </span>
                        </div>
                        {data.payment_method === 'cash' && (
                            <div className="flex justify-between">
                                <span>Dibayar Dari</span>
                                <span className="font-medium">
                                    {cashAccounts.find((a) => a.code === data.cash_account_code)?.name ?? '-'}
                                </span>
                            </div>
                        )}
                    </div>
                    <p className="mt-4 text-sm text-gray-600">
                        Yakin ingin menyimpan aset ini?
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
