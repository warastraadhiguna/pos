import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const formatRupiah = (value) => {
    const number = Number(value);
    const sign = number < 0 ? '-' : '';
    return sign + 'Rp' + Math.round(Math.abs(number)).toLocaleString('id-ID');
};

const TYPE_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense'];
const TYPE_LABEL = {
    asset: 'Aset',
    liability: 'Liabilitas',
    equity: 'Ekuitas',
    revenue: 'Pendapatan',
    expense: 'Beban',
};

// Susun tiap tipe jadi pohon sederhana: akun tanpa induk dulu, lalu
// anak-anaknya langsung di bawahnya (indented) -- cukup untuk satu level
// nesting yang ada sekarang (grup "Kas & Bank").
function buildTree(accounts) {
    const byParent = new Map();
    accounts.forEach((account) => {
        const key = account.parent_id ?? 'root';
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key).push(account);
    });

    const rows = [];
    (byParent.get('root') ?? []).forEach((root) => {
        rows.push({ ...root, depth: 0 });
        (byParent.get(root.id) ?? []).forEach((child) => {
            rows.push({ ...child, depth: 1 });
        });
    });
    return rows;
}

function AccountRow({ account, onToggle }) {
    return (
        <tr>
            <td className="whitespace-nowrap px-6 py-3 text-sm text-gray-600">
                <span style={{ paddingLeft: `${account.depth * 1.25}rem` }}>{account.code}</span>
            </td>
            <td className="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">
                {account.name}
            </td>
            <td className="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                {account.normal_balance === 'debit' ? 'Debit' : 'Kredit'}
            </td>
            <td className="whitespace-nowrap px-6 py-3 text-right text-sm text-gray-900">
                {formatRupiah(account.balance)}
            </td>
            <td className="whitespace-nowrap px-6 py-3 text-sm">
                {account.is_active ? (
                    <span className="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">
                        Aktif
                    </span>
                ) : (
                    <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">
                        Nonaktif
                    </span>
                )}
                {account.is_protected && (
                    <span className="ml-2 text-xs text-gray-400">(sistem)</span>
                )}
            </td>
            <td className="whitespace-nowrap px-6 py-3 text-right text-sm">
                {!account.is_protected && (
                    <SecondaryButton onClick={() => onToggle(account)}>
                        {account.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                    </SecondaryButton>
                )}
            </td>
        </tr>
    );
}

export default function Index({ asOf, accounts }) {
    const { errors } = usePage().props;

    const { data, setData, post, processing, reset } = useForm({
        code: '',
        name: '',
        type: 'asset',
        parent_id: '',
    });

    const changeDate = (e) => {
        router.get(route('coa.index'), { as_of: e.target.value }, { preserveState: true, preserveScroll: true });
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('coa.store'), { onSuccess: () => reset() });
    };

    const toggleActive = (account) => {
        router.put(route('coa.toggle-active', account.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Chart of Accounts
                </h2>
            }
        >
            <Head title="Chart of Accounts" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-4 shadow-sm sm:p-6">
                        <h3 className="mb-4 font-semibold text-gray-900">Tambah Akun</h3>
                        <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                            <div>
                                <InputLabel htmlFor="code" value="Kode" />
                                <TextInput
                                    id="code"
                                    type="text"
                                    className="mt-1 h-10 block"
                                    placeholder="mis. 1-2100"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    required
                                />
                                <InputError className="mt-2" message={errors.code} />
                            </div>
                            <div className="flex-1 min-w-[180px]">
                                <InputLabel htmlFor="name" value="Nama Akun" />
                                <TextInput
                                    id="name"
                                    type="text"
                                    className="mt-1 h-10 block w-full"
                                    placeholder="mis. Deposit Sewa"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                <InputError className="mt-2" message={errors.name} />
                            </div>
                            <div>
                                <InputLabel htmlFor="type" value="Tipe" />
                                <SelectInput
                                    id="type"
                                    className="mt-1 h-10 block"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                >
                                    {TYPE_ORDER.map((type) => (
                                        <option key={type} value={type}>
                                            {TYPE_LABEL[type]}
                                        </option>
                                    ))}
                                </SelectInput>
                                <InputError className="mt-2" message={errors.type} />
                            </div>
                            <div>
                                <InputLabel htmlFor="parent_id" value="Induk (opsional)" />
                                <SelectInput
                                    id="parent_id"
                                    className="mt-1 h-10 block"
                                    value={data.parent_id}
                                    onChange={(e) => setData('parent_id', e.target.value)}
                                >
                                    <option value="">Tidak ada</option>
                                    {accounts
                                        .filter((a) => a.parent_id === null)
                                        .map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.code} — {a.name}
                                            </option>
                                        ))}
                                </SelectInput>
                                <InputError className="mt-2" message={errors.parent_id} />
                            </div>
                            <PrimaryButton disabled={processing} className="h-10">
                                Tambah
                            </PrimaryButton>
                        </form>
                        <p className="mt-3 text-xs text-gray-400">
                            Kode harus berformat "1-2100" (satu digit tipe + tanda hubung + 3-4 digit),
                            dan awalan digitnya harus sesuai tipe (1=Aset, 2=Liabilitas, 3=Ekuitas,
                            4=Pendapatan, 5=Beban). Saldo normal mengikuti tipe secara otomatis. Kode
                            di rentang 1-1x (Kas & Bank) dan 5-1x/5-2x/5-3x (HPP/Selisih Persediaan/Beban
                            Operasional) ditolak di sini — gunakan halaman khususnya masing-masing.
                        </p>
                    </div>

                    <div className="flex items-center justify-end gap-2 rounded-lg bg-white p-4 shadow-sm">
                        <InputLabel htmlFor="as_of" value="Saldo per tanggal" className="mb-0" />
                        <TextInput id="as_of" type="date" value={asOf} onChange={changeDate} />
                    </div>

                    {TYPE_ORDER.map((type) => {
                        const rows = buildTree(accounts.filter((a) => a.type === type));
                        if (rows.length === 0) return null;

                        return (
                            <div key={type} className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                                <h3 className="border-b border-gray-200 p-4 font-semibold text-gray-900">
                                    {TYPE_LABEL[type]}
                                </h3>
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Kode
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Nama
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Saldo Normal
                                            </th>
                                            <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Saldo
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Status
                                            </th>
                                            <th className="px-6 py-3" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 bg-white">
                                        {rows.map((account) => (
                                            <AccountRow key={account.id} account={account} onToggle={toggleActive} />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        );
                    })}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
