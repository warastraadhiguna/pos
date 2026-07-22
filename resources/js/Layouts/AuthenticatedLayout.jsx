import BrandMark from '@/Components/BrandMark';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

const icons = {
    dashboard:
        'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    kasir: 'M3 3h2l.4 2M7 13h10l3-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17M17 17a1 1 0 100 2 1 1 0 000-2zM8 17a1 1 0 100 2 1 1 0 000-2z',
    riwayat:
        'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    pembelian:
        'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7l2 2 4-4',
    stockOpname:
        'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 14l2 2 4-4',
    item: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    produk:
        'M7 7h.01M7 3h5.586a1 1 0 01.707.293l6.414 6.414a1 1 0 010 1.414l-7.586 7.586a1 1 0 01-1.414 0L4.293 12.293A1 1 0 014 11.586V6a3 3 0 013-3z',
    supplier: 'M3 21h18M5 21V7l8-4v18M13 21V11l6 3v7M9 9h.01M9 12h.01M9 15h.01',
    kategori:
        'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
    ukuran: 'M4 6h16M6 6v3m12-3v3M4 18h16M6 18v-3m12 3v-3M4 6l0 12M20 6l0 12',
    neraca:
        'M8 17v-5m4 5V9m4 8v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
    labaRugi: 'M3 17l6-6 4 4 8-8M21 7v6M21 7h-6',
    ppn: 'M9 7h6m-6 4h6m-6 4h4M5 3h14a1 1 0 011 1v16l-4-3-3 3-3-3-3 3-3-3-4 3V4a1 1 0 011-1z',
    pengguna:
        'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-3.13a4 4 0 100-8 4 4 0 000 8zm7 3.13a4 4 0 00-3-3.87',
    shield: 'M12 3l7 4v5c0 5-3.5 8.5-7 9-3.5-.5-7-4-7-9V7l7-4z',
    layers: 'M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5M3 8.5l9 5 9-5',
    chevron: 'M9 5l7 7-7 7',
    collapse: 'M11 19l-7-7 7-7m8 14l-7-7 7-7',
    expand: 'M13 5l7 7-7 7M5 5l7 7-7 7',
    stok: 'M3 3h18v4H3V3zm1 4h16v13a1 1 0 01-1 1H5a1 1 0 01-1-1V7zm4 4h8',
    beban: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 9h6M9 13h6M9 17h4',
};

const navGroups = [
    {
        label: null,
        items: [
            { name: 'Dashboard', href: 'dashboard', match: 'dashboard', icon: icons.dashboard, permission: null },
        ],
    },
    {
        label: 'Transaksi',
        items: [
            {
                name: 'Penjualan',
                icon: icons.kasir,
                children: [
                    {
                        name: 'Kasir',
                        href: 'kasir.index',
                        match: 'kasir.*',
                        icon: icons.kasir,
                        permission: 'kasir.access',
                    },
                    {
                        name: 'Riwayat Penjualan',
                        href: 'penjualan.index',
                        match: 'penjualan.*',
                        icon: icons.riwayat,
                        permission: 'penjualan.view',
                    },
                ],
            },
            {
                name: 'Pembelian',
                icon: icons.pembelian,
                children: [
                    {
                        name: 'Purchase Order',
                        href: 'pembelian.purchase-orders.index',
                        match: 'pembelian.purchase-orders.*',
                        icon: icons.pembelian,
                        permission: 'pembelian.manage',
                    },
                    {
                        name: 'Bayar Hutang Supplier',
                        href: 'pembelian.supplier-payments.index',
                        match: 'pembelian.supplier-payments.*',
                        icon: icons.neraca,
                        permission: 'pembelian.manage',
                    },
                ],
            },
            {
                name: 'Beban Operasional',
                icon: icons.beban,
                children: [
                    {
                        name: 'Catat Beban',
                        href: 'beban.create',
                        match: 'beban.create',
                        icon: icons.beban,
                        permission: 'beban.manage',
                    },
                    {
                        name: 'Riwayat Beban',
                        href: 'beban.index',
                        match: 'beban.index',
                        icon: icons.riwayat,
                        permission: 'beban.manage',
                    },
                    {
                        name: 'Pelunasan Hutang Beban',
                        href: 'beban.payments.index',
                        match: 'beban.payments.*',
                        icon: icons.neraca,
                        permission: 'beban.manage',
                    },
                    {
                        name: 'Kelola Akun Beban',
                        href: 'beban.accounts.index',
                        match: 'beban.accounts.*',
                        icon: icons.kategori,
                        permission: 'beban.manage',
                    },
                ],
            },
            { name: 'Stock Opname', href: 'stock-opname.index', match: 'stock-opname.*', icon: icons.stockOpname, permission: 'stock-opname.manage' },
            {
                name: 'Kas & Bank',
                icon: icons.neraca,
                children: [
                    {
                        name: 'Transfer Kas & Bank',
                        href: 'kas-bank.transfers.index',
                        match: 'kas-bank.transfers.*',
                        icon: icons.neraca,
                        permission: 'kas-bank.manage',
                    },
                    {
                        name: 'Kelola Akun Kas & Bank',
                        href: 'kas-bank.accounts.index',
                        match: 'kas-bank.accounts.*',
                        icon: icons.neraca,
                        permission: 'kas-bank.manage',
                    },
                ],
            },
            {
                name: 'Modal & Prive',
                icon: icons.neraca,
                children: [
                    {
                        name: 'Riwayat Modal & Prive',
                        href: 'modal.index',
                        match: 'modal.index',
                        icon: icons.riwayat,
                        permission: 'modal.manage',
                    },
                    {
                        name: 'Catat Setoran Modal',
                        href: 'modal.deposit.create',
                        match: 'modal.deposit.*',
                        icon: icons.neraca,
                        permission: 'modal.manage',
                    },
                    {
                        name: 'Catat Prive',
                        href: 'modal.withdrawal.create',
                        match: 'modal.withdrawal.*',
                        icon: icons.neraca,
                        permission: 'modal.manage',
                    },
                ],
            },
            {
                name: 'Aset Tetap',
                icon: icons.layers,
                children: [
                    {
                        name: 'Daftar Aset',
                        href: 'aset.index',
                        match: 'aset.index',
                        icon: icons.layers,
                        permission: 'aset.manage',
                    },
                    {
                        name: 'Catat Aset Baru',
                        href: 'aset.create',
                        match: 'aset.create',
                        icon: icons.produk,
                        permission: 'aset.manage',
                    },
                    {
                        name: 'Proses Penyusutan',
                        href: 'aset.depreciation.index',
                        match: 'aset.depreciation.*',
                        icon: icons.riwayat,
                        permission: 'aset.manage',
                    },
                    {
                        name: 'Pelunasan Hutang Aset',
                        href: 'aset.payments.index',
                        match: 'aset.payments.*',
                        icon: icons.neraca,
                        permission: 'aset.manage',
                    },
                ],
            },
        ],
    },
    {
        label: 'Master Data',
        items: [
            {
                name: 'Produk & Item',
                icon: icons.layers,
                children: [
                    {
                        name: 'Produk (dijual ke pelanggan)',
                        href: 'master.products.index',
                        match: 'master.products.*',
                        icon: icons.produk,
                        permission: 'master-data.manage',
                    },
                    {
                        name: 'Kategori Produk',
                        href: 'master.product-categories.index',
                        match: 'master.product-categories.*',
                        icon: icons.kategori,
                        permission: 'master-data.manage',
                    },
                    {
                        name: 'Item (bahan baku & stok)',
                        href: 'master.items.index',
                        match: 'master.items.*',
                        icon: icons.item,
                        permission: 'master-data.manage',
                    },
                    {
                        name: 'Kategori Item',
                        href: 'master.item-categories.index',
                        match: 'master.item-categories.*',
                        icon: icons.kategori,
                        permission: 'master-data.manage',
                    },
                ],
            },
            { name: 'Supplier', href: 'master.suppliers.index', match: 'master.suppliers.*', icon: icons.supplier, permission: 'master-data.manage' },
            { name: 'Ukuran', href: 'master.uoms.index', match: 'master.uoms.*', icon: icons.ukuran, permission: 'master-data.manage' },
        ],
    },
    {
        label: 'Laporan',
        items: [
            { name: 'Neraca', href: 'laporan.neraca', match: 'laporan.neraca', icon: icons.neraca, permission: 'laporan.view' },
            { name: 'Laba Rugi', href: 'laporan.laba-rugi', match: 'laporan.laba-rugi', icon: icons.labaRugi, permission: 'laporan.view' },
            { name: 'Beban Operasional', href: 'laporan.beban', match: 'laporan.beban', icon: icons.beban, permission: 'laporan.view' },
            { name: 'Penjualan', href: 'laporan.penjualan', match: 'laporan.penjualan', icon: icons.riwayat, permission: 'laporan.view' },
            { name: 'Laba per Produk', href: 'laporan.laba-produk', match: 'laporan.laba-produk', icon: icons.labaRugi, permission: 'laporan.view' },
            { name: 'PPN', href: 'laporan.ppn', match: 'laporan.ppn', icon: icons.ppn, permission: 'laporan.view' },
            { name: 'Daftar Stok', href: 'laporan.stok', match: 'laporan.stok', icon: icons.stok, permission: 'laporan.view' },
            { name: 'Hutang Supplier', href: 'laporan.hutang', match: 'laporan.hutang', icon: icons.neraca, permission: 'laporan.view' },
        ],
    },
    {
        label: 'Pengaturan',
        items: [
            { name: 'Pengguna', href: 'users.index', match: 'users.*', icon: icons.pengguna, permission: 'pengguna.manage' },
            { name: 'Role & Izin', href: 'roles.index', match: 'roles.*', icon: icons.shield, permission: 'roles.manage' },
            { name: 'Pengaturan', href: 'pengaturan.index', match: 'pengaturan.*', icon: icons.ppn, permission: 'company-settings.manage' },
            { name: 'Chart of Accounts', href: 'coa.index', match: 'coa.*', icon: icons.neraca, permission: 'coa.manage' },
        ],
    },
];

function SidebarLink({ item, indent = false, collapsed = false }) {
    const active = route().current(item.match);

    return (
        <Link
            href={route(item.href)}
            title={collapsed ? item.name : undefined}
            className={
                'flex items-center gap-3 rounded-lg py-2 text-sm font-medium transition ' +
                (collapsed ? 'justify-center px-2' : indent ? 'ps-11 pe-3' : 'px-3') +
                ' ' +
                (active
                    ? 'bg-primary text-white'
                    : 'text-gray-400 hover:bg-gray-800/60 hover:text-white')
            }
        >
            {item.icon && (
                <svg
                    className="h-5 w-5 shrink-0"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <path d={item.icon} />
                </svg>
            )}
            {!collapsed && item.name}
        </Link>
    );
}

function SidebarParent({ item }) {
    const hasActiveChild = item.children.some((child) => route().current(child.match));
    const [open, setOpen] = useState(hasActiveChild);

    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen((previous) => !previous)}
                className={
                    'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ' +
                    (hasActiveChild
                        ? 'text-white'
                        : 'text-gray-400 hover:bg-gray-800/60 hover:text-white')
                }
            >
                <svg
                    className="h-5 w-5 shrink-0"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <path d={item.icon} />
                </svg>
                <span className="flex-1 text-left">{item.name}</span>
                <svg
                    className={'h-4 w-4 shrink-0 transition-transform ' + (open ? 'rotate-90' : '')}
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <path d={icons.chevron} />
                </svg>
            </button>
            {open && (
                <div className="mt-1 space-y-1">
                    {item.children.map((child) => (
                        <SidebarLink key={child.href} item={child} indent />
                    ))}
                </div>
            )}
        </div>
    );
}

function filterVisibleItem(item, permissions) {
    if (item.children) {
        const children = item.children.filter(
            (child) => !child.permission || permissions.includes(child.permission),
        );

        return children.length > 0 ? { ...item, children } : null;
    }

    return !item.permission || permissions.includes(item.permission) ? item : null;
}

function SidebarNav({ permissions, collapsed = false }) {
    const visibleGroups = navGroups
        .map((group) => ({
            ...group,
            items: group.items
                .map((item) => filterVisibleItem(item, permissions))
                .filter(Boolean),
        }))
        .filter((group) => group.items.length > 0);

    return (
        <nav className="flex-1 space-y-6 overflow-y-auto px-3 pb-6">
            {visibleGroups.map((group) => (
                <div key={group.label ?? 'top'}>
                    {group.label && !collapsed && (
                        <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                            {group.label}
                        </p>
                    )}
                    <div className="space-y-1">
                        {group.items.map((item) => {
                            if (!item.children) {
                                return <SidebarLink key={item.href} item={item} collapsed={collapsed} />;
                            }

                            // Collapsed mode has no room for a flyout submenu, so the
                            // "Produk & Item" parent just flattens into its children
                            // as plain icon links instead of an expandable group.
                            if (collapsed) {
                                return item.children.map((child) => (
                                    <SidebarLink key={child.href} item={child} collapsed />
                                ));
                            }

                            return <SidebarParent key={item.name} item={item} />;
                        })}
                    </div>
                </div>
            ))}
        </nav>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const permissions = usePage().props.auth.permissions;
    const flash = usePage().props.flash;

    const [showingMobileNav, setShowingMobileNav] = useState(false);
    const [collapsed, setCollapsed] = useState(
        () => localStorage.getItem('sidebar-collapsed') === 'true',
    );

    const toggleCollapsed = () => {
        setCollapsed((previous) => {
            const next = !previous;
            localStorage.setItem('sidebar-collapsed', String(next));
            return next;
        });
    };

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Desktop sidebar */}
            <aside
                className={
                    'fixed inset-y-0 hidden flex-col bg-gray-900 transition-all duration-200 lg:flex ' +
                    (collapsed ? 'w-16' : 'w-64')
                }
            >
                <div className={'flex items-center py-5 ' + (collapsed ? 'justify-center px-2' : 'justify-between px-6')}>
                    <Link href="/">
                        <BrandMark />
                    </Link>
                    {!collapsed && (
                        <button
                            type="button"
                            onClick={toggleCollapsed}
                            className="text-gray-400 hover:text-white"
                            aria-label="Ciutkan menu"
                            title="Ciutkan menu"
                        >
                            <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d={icons.collapse} />
                            </svg>
                        </button>
                    )}
                </div>
                {collapsed && (
                    <button
                        type="button"
                        onClick={toggleCollapsed}
                        className="mx-auto mb-2 text-gray-400 hover:text-white"
                        aria-label="Perluas menu"
                        title="Perluas menu"
                    >
                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d={icons.expand} />
                        </svg>
                    </button>
                )}
                <SidebarNav permissions={permissions} collapsed={collapsed} />
            </aside>

            {/* Mobile sidebar (slide-over) */}
            {showingMobileNav && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div
                        className="fixed inset-0 bg-gray-900/60"
                        onClick={() => setShowingMobileNav(false)}
                    />
                    <aside className="relative flex h-full w-64 flex-col bg-gray-900">
                        <div className="flex items-center justify-between px-6 py-5">
                            <Link href="/" onClick={() => setShowingMobileNav(false)}>
                                <BrandMark />
                            </Link>
                            <button
                                onClick={() => setShowingMobileNav(false)}
                                className="text-gray-400 hover:text-white"
                                aria-label="Tutup menu"
                            >
                                <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div onClick={() => setShowingMobileNav(false)}>
                            <SidebarNav permissions={permissions} />
                        </div>
                    </aside>
                </div>
            )}

            {/* Main column */}
            <div className={'flex min-h-screen flex-col transition-all duration-200 ' + (collapsed ? 'lg:pl-16' : 'lg:pl-64')}>
                <header className="sticky top-0 z-10 flex h-16 items-center gap-4 border-b border-gray-200 bg-white px-4 sm:px-6 lg:px-8">
                    <button
                        onClick={() => setShowingMobileNav(true)}
                        className="text-gray-500 hover:text-gray-700 lg:hidden"
                        aria-label="Buka menu"
                    >
                        <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    {header && (
                        <div className="min-w-0 flex-1 text-lg font-semibold leading-tight text-gray-800">
                            {header}
                        </div>
                    )}

                    <div className="ms-auto">
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-2 rounded-md border border-transparent px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                >
                                    {user.name}
                                    <svg
                                        className="h-4 w-4"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fillRule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </Dropdown.Trigger>

                            <Dropdown.Content>
                                <Dropdown.Link href={route('profile.edit')}>
                                    Profile
                                </Dropdown.Link>
                                <Dropdown.Link
                                    href={route('logout')}
                                    method="post"
                                    as="button"
                                >
                                    Log Out
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                {(flash?.success || flash?.error) && (
                    <div className="px-4 pt-4 sm:px-6 lg:px-8">
                        {flash.success && (
                            <div className="rounded-md bg-green-50 p-4 text-sm font-medium text-green-700">
                                {flash.success}
                            </div>
                        )}
                        {flash.error && (
                            <div className="rounded-md bg-red-50 p-4 text-sm font-medium text-red-700">
                                {flash.error}
                            </div>
                        )}
                    </div>
                )}

                <main className="flex-1">{children}</main>
            </div>
        </div>
    );
}
