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
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value || 0)).toLocaleString('id-ID');

export default function Create({ outlets, cashAccounts }) {
    // Default arah Kas -> Bank (kasus paling umum: setor hasil jualan ke
    // bank), gampang dibalik lewat tombol "Tukar".
    const kas = cashAccounts.find((a) => a.code === '1-1000');
    const firstBank = cashAccounts.find((a) => a.code !== '1-1000');

    const { data, setData, post, processing, errors } = useForm({
        outlet_id: outlets[0]?.id ?? '',
        date: new Date().toISOString().slice(0, 10),
        from_account_code: kas?.code ?? cashAccounts[0]?.code ?? '',
        to_account_code: firstBank?.code ?? cashAccounts[1]?.code ?? '',
        amount: '',
        memo: '',
    });

    const [showConfirm, setShowConfirm] = useState(false);

    const swap = () => {
        setData({
            ...data,
            from_account_code: data.to_account_code,
            to_account_code: data.from_account_code,
        });
    };

    const submit = (e) => {
        e.preventDefault();
        setShowConfirm(true);
    };

    const confirmSave = () => {
        setShowConfirm(false);
        post(route('kas-bank.transfers.store'));
    };

    const nameFor = (code) => cashAccounts.find((a) => a.code === code)?.name ?? code;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Transfer Kas & Bank
                </h2>
            }
        >
            <Head title="Transfer Kas & Bank" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form onSubmit={submit} className="space-y-6">
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

                            <div className="flex items-end gap-3">
                                <div className="flex-1">
                                    <InputLabel htmlFor="from_account_code" value="Dari" />
                                    <SelectInput
                                        id="from_account_code"
                                        className="mt-1 h-10 block w-full"
                                        value={data.from_account_code}
                                        onChange={(e) => setData('from_account_code', e.target.value)}
                                        required
                                    >
                                        {cashAccounts.map((account) => (
                                            <option key={account.code} value={account.code}>
                                                {account.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </div>

                                <SecondaryButton type="button" className="h-10" onClick={swap}>
                                    ⇄ Tukar
                                </SecondaryButton>

                                <div className="flex-1">
                                    <InputLabel htmlFor="to_account_code" value="Ke" />
                                    <SelectInput
                                        id="to_account_code"
                                        className="mt-1 h-10 block w-full"
                                        value={data.to_account_code}
                                        onChange={(e) => setData('to_account_code', e.target.value)}
                                        required
                                    >
                                        {cashAccounts.map((account) => (
                                            <option key={account.code} value={account.code}>
                                                {account.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </div>
                            </div>
                            <InputError message={errors.from_account_code} />
                            <InputError message={errors.to_account_code} />

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
                                <InputLabel htmlFor="memo" value="Catatan (opsional)" />
                                <TextInput
                                    id="memo"
                                    type="text"
                                    className="mt-1 block w-full"
                                    placeholder='mis. "Setor hasil jualan minggu ini"'
                                    value={data.memo}
                                    onChange={(e) => setData('memo', e.target.value)}
                                />
                                <InputError className="mt-2" message={errors.memo} />
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>Simpan Transfer</PrimaryButton>
                                <Link href={route('kas-bank.transfers.index')}>
                                    <SecondaryButton type="button">Batal</SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <Modal show={showConfirm} onClose={() => setShowConfirm(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Konfirmasi Transfer</h3>
                    <div className="mt-4 space-y-1 rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                        <div className="flex justify-between">
                            <span>Dari</span>
                            <span className="font-medium">{nameFor(data.from_account_code)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span>Ke</span>
                            <span className="font-medium">{nameFor(data.to_account_code)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span>Jumlah</span>
                            <span className="font-medium">{formatRupiah(data.amount)}</span>
                        </div>
                    </div>
                    <p className="mt-4 text-sm text-gray-600">Yakin ingin menyimpan transfer ini?</p>
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
