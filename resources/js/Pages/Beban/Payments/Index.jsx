import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

const statusLabel = {
    belum: { text: 'Belum Dibayar', className: 'bg-red-100 text-red-700' },
    sebagian: { text: 'Dibayar Sebagian', className: 'bg-amber-100 text-amber-700' },
};

export default function Index({ outlets, unpaidExpenses, cashAccounts }) {
    const [payingExpense, setPayingExpense] = useState(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        outlet_id: outlets[0]?.id ?? '',
        expense_id: '',
        date: new Date().toISOString().slice(0, 10),
        amount: '',
        cash_account_code: cashAccounts[0]?.code ?? '',
        memo: '',
    });

    const openPayModal = (expense) => {
        setPayingExpense(expense);
        setData({
            outlet_id: outlets[0]?.id ?? '',
            expense_id: expense.expense_id,
            date: new Date().toISOString().slice(0, 10),
            amount: expense.remaining,
            cash_account_code: cashAccounts[0]?.code ?? '',
            memo: '',
        });
    };

    const closeModal = () => {
        setPayingExpense(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('beban.payments.store'), {
            onSuccess: () => closeModal(),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Pelunasan Hutang Beban
                </h2>
            }
        >
            <Head title="Pelunasan Hutang Beban" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tanggal
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Keterangan
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Total Beban
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Sudah Dibayar
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Sisa
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {unpaidExpenses.map((expense) => (
                                    <tr key={expense.expense_id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDate(expense.date)}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            {expense.description}
                                            {expense.payee && (
                                                <span className="text-gray-400"> ({expense.payee})</span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatRupiah(expense.expense_total)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-600">
                                            {formatRupiah(expense.paid)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900">
                                            {formatRupiah(expense.remaining)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            <span
                                                className={`rounded-full px-2 py-1 text-xs font-medium ${statusLabel[expense.status]?.className}`}
                                            >
                                                {statusLabel[expense.status]?.text}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <SecondaryButton onClick={() => openPayModal(expense)}>
                                                Bayar
                                            </SecondaryButton>
                                        </td>
                                    </tr>
                                ))}
                                {unpaidExpenses.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Tidak ada hutang beban yang belum lunas.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={payingExpense !== null} onClose={closeModal} maxWidth="md">
                {payingExpense && (
                    <form onSubmit={submit} className="p-6">
                        <h3 className="text-lg font-medium text-gray-900">
                            Bayar: {payingExpense.description}
                        </h3>
                        <p className="mt-1 text-sm text-gray-500">
                            Sisa hutang: {formatRupiah(payingExpense.remaining)}
                        </p>

                        <div className="mt-4">
                            <InputLabel htmlFor="pay_date" value="Tanggal Bayar" />
                            <TextInput
                                id="pay_date"
                                type="date"
                                className="mt-1 h-10 block w-full"
                                value={data.date}
                                onChange={(e) => setData('date', e.target.value)}
                                required
                            />
                            <InputError className="mt-2" message={errors.date} />
                        </div>

                        <div className="mt-4">
                            <InputLabel htmlFor="pay_amount" value="Jumlah Dibayar" />
                            <NumberInput
                                id="pay_amount"
                                className="mt-1 h-10 block w-full"
                                maxDecimals={0}
                                value={data.amount}
                                onChange={(plain) => setData('amount', plain)}
                                required
                            />
                            <InputError className="mt-2" message={errors.amount} />
                        </div>

                        <div className="mt-4">
                            <InputLabel htmlFor="pay_cash_account_code" value="Dibayar Dari" />
                            <SelectInput
                                id="pay_cash_account_code"
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

                        <div className="mt-4">
                            <InputLabel htmlFor="pay_memo" value="Catatan (opsional)" />
                            <TextInput
                                id="pay_memo"
                                type="text"
                                className="mt-1 block w-full"
                                value={data.memo}
                                onChange={(e) => setData('memo', e.target.value)}
                            />
                            <InputError className="mt-2" message={errors.memo} />
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={closeModal}>
                                Batal
                            </SecondaryButton>
                            <PrimaryButton disabled={processing}>Simpan Pembayaran</PrimaryButton>
                        </div>
                    </form>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
