import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
// Harga per satuan DASAR bisa pecahan (mis. Rp0,15/gram) -- jangan dibulatkan ke rupiah.
const formatPrice = (value) => 'Rp' + Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });
const formatQty = (value) => Number(value).toLocaleString('id-ID', { maximumFractionDigits: 4 });

const paymentLabel = {
    cash: 'Tunai',
    credit: 'Kredit (Hutang)',
};

const thClass = 'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500';
const thRightClass = 'px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500';
const tdClass = 'whitespace-nowrap px-6 py-4 text-sm text-gray-600';
const tdRightClass = 'whitespace-nowrap px-6 py-4 text-right text-sm text-gray-900';

export default function PerItem({ rows, recap, filters, suppliers, categories, summary }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [search, setSearch] = useState(filters.search);
    const [supplierId, setSupplierId] = useState(filters.supplier_id ?? '');
    const [paymentMethod, setPaymentMethod] = useState(filters.payment_method ?? '');
    const [categoryId, setCategoryId] = useState(filters.item_category_id ?? '');
    const [view, setView] = useState('recap');

    const applyFilters = (overrides = {}) => {
        router.get(
            route('pembelian.item-history.index'),
            {
                date_from: dateFrom,
                date_to: dateTo,
                search,
                supplier_id: supplierId,
                payment_method: paymentMethod,
                item_category_id: categoryId,
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitFilters = (e) => {
        e.preventDefault();
        applyFilters();
    };

    const resetToLast30Days = () => {
        const today = new Date().toISOString().slice(0, 10);
        const thirtyDaysAgo = new Date(Date.now() - 29 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
        setDateFrom(thirtyDaysAgo);
        setDateTo(today);
        setSearch('');
        setSupplierId('');
        setPaymentMethod('');
        setCategoryId('');
        applyFilters({
            date_from: thirtyDaysAgo,
            date_to: today,
            search: '',
            supplier_id: '',
            payment_method: '',
            item_category_id: '',
        });
    };

    const tabClass = (active) =>
        'rounded-md px-4 py-2 text-sm font-medium ' +
        (active ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-100');

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Riwayat Pembelian per Item
                </h2>
            }
        >
            <Head title="Riwayat Pembelian per Item" />

            <div className="py-12">
                <div className="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
                    <form
                        onSubmit={submitFilters}
                        className="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm"
                    >
                        <div>
                            <InputLabel htmlFor="date_from" value="Dari Tanggal" />
                            <TextInput
                                id="date_from"
                                type="date"
                                className="mt-1 h-10"
                                value={dateFrom}
                                onChange={(e) => {
                                    setDateFrom(e.target.value);
                                    applyFilters({ date_from: e.target.value });
                                }}
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="date_to" value="Sampai Tanggal" />
                            <TextInput
                                id="date_to"
                                type="date"
                                className="mt-1 h-10"
                                value={dateTo}
                                onChange={(e) => {
                                    setDateTo(e.target.value);
                                    applyFilters({ date_to: e.target.value });
                                }}
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="supplier_id" value="Supplier" />
                            <SelectInput
                                id="supplier_id"
                                className="mt-1 h-10"
                                value={supplierId}
                                onChange={(e) => {
                                    setSupplierId(e.target.value);
                                    applyFilters({ supplier_id: e.target.value });
                                }}
                            >
                                <option value="">Semua Supplier</option>
                                {suppliers.map((supplier) => (
                                    <option key={supplier.id} value={supplier.id}>
                                        {supplier.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel htmlFor="payment_method" value="Metode Bayar" />
                            <SelectInput
                                id="payment_method"
                                className="mt-1 h-10"
                                value={paymentMethod}
                                onChange={(e) => {
                                    setPaymentMethod(e.target.value);
                                    applyFilters({ payment_method: e.target.value });
                                }}
                            >
                                <option value="">Semua Metode</option>
                                <option value="cash">Tunai</option>
                                <option value="credit">Kredit (Hutang)</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel htmlFor="item_category_id" value="Kategori" />
                            <SelectInput
                                id="item_category_id"
                                className="mt-1 h-10"
                                value={categoryId}
                                onChange={(e) => {
                                    setCategoryId(e.target.value);
                                    applyFilters({ item_category_id: e.target.value });
                                }}
                            >
                                <option value="">Semua Kategori</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>
                        <div className="flex-1 min-w-[200px]">
                            <InputLabel htmlFor="search" value="Cari (No. PO / Item)" />
                            <TextInput
                                id="search"
                                type="text"
                                className="mt-1 h-10 block w-full"
                                placeholder="mis. PO-12, SKU, atau nama item"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Aksi" className="invisible" />
                            <div className="mt-1 flex gap-2">
                                <PrimaryButton type="submit" className="h-10">
                                    Cari
                                </PrimaryButton>
                                <SecondaryButton
                                    type="button"
                                    className="h-10"
                                    onClick={resetToLast30Days}
                                >
                                    30 Hari Terakhir
                                </SecondaryButton>
                            </div>
                        </div>
                    </form>

                    <div className="flex flex-wrap gap-4 rounded-lg bg-white p-4 shadow-sm">
                        <div className="text-sm text-gray-600">
                            Jumlah Item:{' '}
                            <span className="font-semibold text-gray-900">{recap.length}</span>
                        </div>
                        <div className="text-sm text-gray-600">
                            Total Nilai:{' '}
                            <span className="font-semibold text-gray-900">{formatRupiah(summary.total)}</span>
                        </div>
                        {summary.by_method.map((row) => (
                            <div key={row.payment_method} className="text-sm text-gray-600">
                                {paymentLabel[row.payment_method] ?? row.payment_method}:{' '}
                                <span className="font-semibold text-gray-900">{formatRupiah(row.total)}</span>{' '}
                                <span className="text-gray-400">({row.count} nota)</span>
                            </div>
                        ))}
                        <div className="w-full text-xs text-gray-400">
                            Berdasarkan barang yang sudah diterima. Qty &amp; harga dalam satuan dasar item, nilai
                            sebelum PPN.
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="button" className={tabClass(view === 'recap')} onClick={() => setView('recap')}>
                            Rekap per Item
                        </button>
                        <button type="button" className={tabClass(view === 'detail')} onClick={() => setView('detail')}>
                            Rincian Penerimaan ({summary.line_count})
                        </button>
                    </div>

                    <div className="w-full overflow-x-auto overscroll-x-contain bg-white shadow-sm sm:rounded-lg">
                        {view === 'recap' ? (
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={thClass}>Item</th>
                                        <th className={thClass}>Kategori</th>
                                        <th className={thRightClass}>Jml Nota</th>
                                        <th className={thRightClass}>Qty</th>
                                        <th className={thRightClass}>Harga Rata-rata</th>
                                        <th className={thRightClass}>Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {recap.map((row) => (
                                        <tr key={row.sku}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                <span className="font-medium">{row.item_name}</span>{' '}
                                                <span className="text-gray-400">({row.sku})</span>
                                            </td>
                                            <td className={tdClass}>{row.category ?? '-'}</td>
                                            <td className={tdRightClass}>{row.receipt_count}</td>
                                            <td className={tdRightClass}>
                                                {formatQty(row.qty)} {row.uom}
                                            </td>
                                            <td className={tdRightClass}>
                                                {formatPrice(row.average_cost)}/{row.uom}
                                            </td>
                                            <td className={tdRightClass + ' font-medium'}>{formatRupiah(row.total)}</td>
                                        </tr>
                                    ))}
                                    {recap.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="px-6 py-6 text-center text-sm text-gray-500">
                                                Tidak ada pembelian yang cocok dengan filter.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        ) : (
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={thClass}>No. PO</th>
                                        <th className={thClass}>Tgl Terima</th>
                                        <th className={thClass}>Item</th>
                                        <th className={thClass}>Kategori</th>
                                        <th className={thClass}>Supplier</th>
                                        <th className={thClass}>Pembayaran</th>
                                        <th className={thRightClass}>Qty</th>
                                        <th className={thRightClass}>Harga</th>
                                        <th className={thRightClass}>Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {rows.map((row) => (
                                        <tr key={row.id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium">
                                                <Link
                                                    href={route('pembelian.purchase-orders.show', row.purchase_order_id)}
                                                    className="text-primary hover:text-primary-dark"
                                                >
                                                    PO-{row.purchase_order_id}
                                                </Link>
                                            </td>
                                            <td className={tdClass}>{row.date}</td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                {row.item_name}{' '}
                                                <span className="text-gray-400">({row.sku})</span>
                                            </td>
                                            <td className={tdClass}>{row.category ?? '-'}</td>
                                            <td className={tdClass}>{row.supplier ?? '-'}</td>
                                            <td className={tdClass}>
                                                {paymentLabel[row.payment_method] ?? row.payment_method}
                                            </td>
                                            <td className={tdRightClass}>
                                                {formatQty(row.qty)} {row.uom}
                                            </td>
                                            <td className={tdRightClass}>{formatPrice(row.unit_cost)}</td>
                                            <td className={tdRightClass + ' font-medium'}>{formatRupiah(row.line_total)}</td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan={9} className="px-6 py-6 text-center text-sm text-gray-500">
                                                Tidak ada pembelian yang cocok dengan filter.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
