import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatQty = (value) => Number(value).toLocaleString('id-ID', { maximumFractionDigits: 4 });

// Sama dengan Penjualan/Index.jsx -- `occurred_at` selalu diformat
// eksplisit ke Asia/Jakarta, `date` jadi fallback untuk baris lama.
const formatDateTimeWIB = (occurredAt, fallbackDate) => {
    if (!occurredAt) {
        return `${fallbackDate} (jam tidak tercatat)`;
    }
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(occurredAt));
    const get = (type) => parts.find((p) => p.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')} WIB`;
};

const statusLabel = {
    completed: 'Selesai',
    void: 'Batal',
    refunded: 'Refund',
};

const paymentLabel = {
    cash: 'Tunai',
    qris: 'QRIS',
};

const thClass = 'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500';
const thRightClass = 'px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500';
const tdClass = 'whitespace-nowrap px-6 py-4 text-sm text-gray-600';
const tdRightClass = 'whitespace-nowrap px-6 py-4 text-right text-sm text-gray-900';

export default function PerProduk({ rows, recap, filters, cashiers, categories, summary }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [search, setSearch] = useState(filters.search);
    const [cashierId, setCashierId] = useState(filters.cashier_id ?? '');
    const [paymentMethod, setPaymentMethod] = useState(filters.payment_method ?? '');
    const [categoryId, setCategoryId] = useState(filters.product_category_id ?? '');
    const [view, setView] = useState('recap');

    const applyFilters = (overrides = {}) => {
        router.get(
            route('penjualan-per-produk.index'),
            {
                date_from: dateFrom,
                date_to: dateTo,
                search,
                cashier_id: cashierId,
                payment_method: paymentMethod,
                product_category_id: categoryId,
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitFilters = (e) => {
        e.preventDefault();
        applyFilters();
    };

    const resetToLast7Days = () => {
        const today = new Date().toISOString().slice(0, 10);
        const sevenDaysAgo = new Date(Date.now() - 6 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
        setDateFrom(sevenDaysAgo);
        setDateTo(today);
        setSearch('');
        setCashierId('');
        setPaymentMethod('');
        setCategoryId('');
        applyFilters({
            date_from: sevenDaysAgo,
            date_to: today,
            search: '',
            cashier_id: '',
            payment_method: '',
            product_category_id: '',
        });
    };

    const tabClass = (active) =>
        'rounded-md px-4 py-2 text-sm font-medium ' +
        (active ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-100');

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Riwayat Penjualan per Produk
                </h2>
            }
        >
            <Head title="Riwayat Penjualan per Produk" />

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
                            <InputLabel htmlFor="cashier_id" value="Kasir" />
                            <SelectInput
                                id="cashier_id"
                                className="mt-1 h-10"
                                value={cashierId}
                                onChange={(e) => {
                                    setCashierId(e.target.value);
                                    applyFilters({ cashier_id: e.target.value });
                                }}
                            >
                                <option value="">Semua Kasir</option>
                                {cashiers.map((cashier) => (
                                    <option key={cashier.id} value={cashier.id}>
                                        {cashier.name}
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
                                <option value="qris">QRIS</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel htmlFor="product_category_id" value="Kategori" />
                            <SelectInput
                                id="product_category_id"
                                className="mt-1 h-10"
                                value={categoryId}
                                onChange={(e) => {
                                    setCategoryId(e.target.value);
                                    applyFilters({ product_category_id: e.target.value });
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
                            <InputLabel htmlFor="search" value="Cari (No. Transaksi / Produk)" />
                            <TextInput
                                id="search"
                                type="text"
                                className="mt-1 h-10 block w-full"
                                placeholder="mis. #123 atau nama produk"
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
                                    onClick={resetToLast7Days}
                                >
                                    7 Hari Terakhir
                                </SecondaryButton>
                            </div>
                        </div>
                    </form>

                    <div className="flex flex-wrap gap-4 rounded-lg bg-white p-4 shadow-sm">
                        <div className="text-sm text-gray-600">
                            Jumlah Produk:{' '}
                            <span className="font-semibold text-gray-900">{recap.length}</span>
                        </div>
                        <div className="text-sm text-gray-600">
                            Total Qty Terjual:{' '}
                            <span className="font-semibold text-gray-900">{formatQty(summary.qty)}</span>
                        </div>
                        <div className="text-sm text-gray-600">
                            Total Nilai:{' '}
                            <span className="font-semibold text-gray-900">{formatRupiah(summary.total)}</span>
                        </div>
                        {summary.void_line_count > 0 && (
                            <div className="text-sm text-gray-400">
                                ({summary.void_line_count} baris dari transaksi batal tidak dihitung)
                            </div>
                        )}
                        <div className="w-full text-xs text-gray-400">
                            Nilai = qty × harga per baris, sebelum diskon per transaksi.
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="button" className={tabClass(view === 'recap')} onClick={() => setView('recap')}>
                            Rekap per Produk
                        </button>
                        <button type="button" className={tabClass(view === 'detail')} onClick={() => setView('detail')}>
                            Rincian Transaksi ({summary.line_count})
                        </button>
                    </div>

                    <div className="w-full overflow-x-auto overscroll-x-contain bg-white shadow-sm sm:rounded-lg">
                        {view === 'recap' ? (
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={thClass}>Produk</th>
                                        <th className={thClass}>Kategori</th>
                                        <th className={thRightClass}>Jml Transaksi</th>
                                        <th className={thRightClass}>Qty</th>
                                        <th className={thRightClass}>Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {recap.map((row) => (
                                        <tr key={row.key}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {row.product_name}
                                            </td>
                                            <td className={tdClass}>{row.category ?? '-'}</td>
                                            <td className={tdRightClass}>{row.transaction_count}</td>
                                            <td className={tdRightClass}>{formatQty(row.qty)}</td>
                                            <td className={tdRightClass + ' font-medium'}>{formatRupiah(row.total)}</td>
                                        </tr>
                                    ))}
                                    {recap.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="px-6 py-6 text-center text-sm text-gray-500">
                                                Tidak ada produk terjual yang cocok dengan filter.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        ) : (
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className={thClass}>No. Transaksi</th>
                                        <th className={thClass}>Tanggal &amp; Jam</th>
                                        <th className={thClass}>Produk</th>
                                        <th className={thClass}>Kategori</th>
                                        <th className={thClass}>Kasir</th>
                                        <th className={thClass}>Pembayaran</th>
                                        <th className={thClass}>Status</th>
                                        <th className={thRightClass}>Qty</th>
                                        <th className={thRightClass}>Harga</th>
                                        <th className={thRightClass}>Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {rows.map((row) => (
                                        <tr key={row.id} className={row.status === 'void' ? 'opacity-50' : ''}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium">
                                                <Link
                                                    href={route('penjualan.show', row.sale_id)}
                                                    className="text-primary hover:text-primary-dark"
                                                >
                                                    #{row.sale_id}
                                                </Link>
                                            </td>
                                            <td className={tdClass}>{formatDateTimeWIB(row.occurred_at, row.date)}</td>
                                            <td className="px-6 py-4 text-sm text-gray-900">
                                                {row.product_name}
                                                {row.variations.length > 0 && (
                                                    <div className="text-xs text-gray-500">
                                                        {row.variations.join(', ')}
                                                    </div>
                                                )}
                                            </td>
                                            <td className={tdClass}>{row.category ?? '-'}</td>
                                            <td className={tdClass}>{row.cashier ?? '-'}</td>
                                            <td className={tdClass}>
                                                {paymentLabel[row.payment_method] ?? row.payment_method}
                                            </td>
                                            <td className={tdClass}>{statusLabel[row.status] ?? row.status}</td>
                                            <td className={tdRightClass}>{formatQty(row.qty)}</td>
                                            <td className={tdRightClass}>{formatRupiah(row.unit_price)}</td>
                                            <td className={tdRightClass + ' font-medium'}>{formatRupiah(row.line_total)}</td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan={10} className="px-6 py-6 text-center text-sm text-gray-500">
                                                Tidak ada penjualan yang cocok dengan filter.
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
