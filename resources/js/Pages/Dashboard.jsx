import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

const quickActions = [
    {
        label: 'Buka Kasir',
        description: 'Mulai transaksi penjualan baru.',
        href: 'kasir.index',
        accent: 'bg-primary',
        icon: (
            <path d="M3 3h2l.4 2M7 13h10l3-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17M17 17a1 1 0 100 2 1 1 0 000-2zM8 17a1 1 0 100 2 1 1 0 000-2z" />
        ),
    },
    {
        label: 'Buat Purchase Order',
        description: 'Pesan barang dari supplier.',
        href: 'pembelian.purchase-orders.create',
        accent: 'bg-amber-500',
        icon: <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7l2 2 4-4" />,
    },
    {
        label: 'Stock Opname',
        description: 'Cocokkan stok sistem dengan fisik.',
        href: 'stock-opname.create',
        accent: 'bg-emerald-500',
        icon: <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 14l2 2 4-4" />,
    },
    {
        label: 'Lihat Laporan',
        description: 'Neraca dan laba rugi terkini.',
        href: 'laporan.neraca',
        accent: 'bg-slate-700',
        icon: <path d="M9 19V6l7 5-7 5zM4 5v14M20 5v14" />,
    },
];

const statusLabel = {
    completed: 'Selesai',
    void: 'Batal',
    refunded: 'Refund',
};

export default function Dashboard({ stats, recentSales }) {
    const user = usePage().props.auth.user;

    const cards = [
        {
            label: 'Penjualan Hari Ini',
            value: formatRupiah(stats.sales_today_total),
            hint: `${stats.sales_today_count} transaksi`,
        },
        {
            label: 'Penjualan Bulan Ini',
            value: formatRupiah(stats.sales_month_total),
            hint: 'Total sampai hari ini',
        },
        {
            label: 'Produk Aktif',
            value: stats.active_products_count,
            hint: 'Siap dijual di kasir',
        },
        {
            label: 'PO Menunggu',
            value: stats.open_purchase_orders_count,
            hint: 'Belum diterima penuh',
        },
    ];

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-8 sm:px-6 lg:px-8">
                    <div className="overflow-hidden rounded-2xl bg-gray-900 px-8 py-10 text-white shadow-lg">
                        <p className="text-sm text-gray-400">
                            Selamat datang kembali,
                        </p>
                        <h3 className="mt-1 text-2xl font-semibold">
                            {user.name}
                        </h3>
                        <p className="mt-2 max-w-xl text-sm text-gray-400">
                            Ini ringkasan toko Anda hari ini. Gunakan pintasan
                            di bawah untuk langsung mulai bekerja.
                        </p>
                    </div>

                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        {cards.map((card) => (
                            <div
                                key={card.label}
                                className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
                            >
                                <p className="text-sm text-gray-500">
                                    {card.label}
                                </p>
                                <p className="mt-2 text-2xl font-semibold text-gray-900">
                                    {card.value}
                                </p>
                                <p className="mt-1 text-xs text-gray-400">
                                    {card.hint}
                                </p>
                            </div>
                        ))}
                    </div>

                    <div>
                        <h4 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            Pintasan
                        </h4>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {quickActions.map((action) => (
                                <Link
                                    key={action.label}
                                    href={route(action.href)}
                                    className="group rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <span
                                        className={
                                            'flex h-10 w-10 items-center justify-center rounded-lg text-white ' +
                                            action.accent
                                        }
                                    >
                                        <svg
                                            className="h-5 w-5"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        >
                                            {action.icon}
                                        </svg>
                                    </span>
                                    <p className="mt-3 font-medium text-gray-900">
                                        {action.label}
                                    </p>
                                    <p className="mt-1 text-sm text-gray-500">
                                        {action.description}
                                    </p>
                                </Link>
                            ))}
                        </div>
                    </div>

                    <div className="rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                            <h4 className="font-semibold text-gray-900">
                                Transaksi Terbaru
                            </h4>
                            <Link
                                href={route('kasir.index')}
                                className="text-sm text-primary hover:text-primary-dark"
                            >
                                Ke Kasir →
                            </Link>
                        </div>
                        <table className="min-w-full divide-y divide-gray-100">
                            <tbody className="divide-y divide-gray-100">
                                {recentSales.map((sale) => (
                                    <tr key={sale.id}>
                                        <td className="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">
                                            #{sale.id}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                                            {formatDate(sale.date)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                                            {statusLabel[sale.status] ??
                                                sale.status}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-3 text-right text-sm font-medium text-gray-900">
                                            {formatRupiah(sale.grand_total)}
                                        </td>
                                    </tr>
                                ))}
                                {recentSales.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-6 py-6 text-center text-sm text-gray-500"
                                        >
                                            Belum ada transaksi.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
