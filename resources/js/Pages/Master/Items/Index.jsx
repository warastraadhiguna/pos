import DangerButton from '@/Components/DangerButton';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export default function Index({ items, search }) {
    const [query, setQuery] = useState(search ?? '');
    const debounceRef = useRef(null);
    // `search` (prop dari server) sengaja TIDAK dijadikan dependency --
    // ini murni menghindari request pencarian pertama kali halaman dimuat
    // (nilai awalnya sendiri berasal dari server, mengirimkannya balik
    // cuma buang-buang request). Perubahan URL lewat Pagination/back-
    // forward browser tetap tersinkron lewat Inertia sendiri (re-render
    // props baru), bukan lewat effect ini.
    useEffect(() => {
        return () => clearTimeout(debounceRef.current);
    }, []);

    const handleSearchChange = (value) => {
        setQuery(value);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            router.get(
                route('master.items.index'),
                { q: value },
                { preserveState: true, replace: true },
            );
        }, 300);
    };

    const destroy = (item) => {
        if (confirm(`Hapus item "${item.name}"?`)) {
            router.delete(route('master.items.destroy', item.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Item
                </h2>
            }
        >
            <Head title="Item" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">
                        Item adalah bahan baku internal yang stoknya dilacak (mis. "Kopi Bubuk", "Cup").
                        Item bukan yang dijual langsung ke pelanggan — Item dirangkai jadi Produk lewat
                        resep (BOM) di halaman Produk.
                    </p>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <TextInput
                            type="search"
                            value={query}
                            onChange={(e) => handleSearchChange(e.target.value)}
                            placeholder="Cari SKU atau nama..."
                            className="w-full max-w-xs"
                        />
                        <Link href={route('master.items.create')}>
                            <PrimaryButton>Tambah Item</PrimaryButton>
                        </Link>
                    </div>

                    <div className="w-full overflow-x-auto overscroll-x-contain bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        SKU
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nama
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Kategori
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tipe Costing
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Ukuran Dasar
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {items.data.map((item) => (
                                    <tr key={item.id}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {item.sku}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {item.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {item.item_category?.name ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {item.costing_type === 'stocked'
                                                ? 'Dilacak stok'
                                                : 'Cost only'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {item.base_uom?.code}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            <span
                                                className={
                                                    item.is_active
                                                        ? 'text-green-700'
                                                        : 'text-gray-400'
                                                }
                                            >
                                                {item.is_active
                                                    ? 'Aktif'
                                                    : 'Nonaktif'}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <Link
                                                href={route(
                                                    'master.items.edit',
                                                    item.id,
                                                )}
                                                className="text-primary hover:text-primary-dark"
                                            >
                                                Edit
                                            </Link>
                                            <DangerButton
                                                className="ms-4"
                                                onClick={() => destroy(item)}
                                            >
                                                Hapus
                                            </DangerButton>
                                        </td>
                                    </tr>
                                ))}
                                {items.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-6 py-4 text-center text-sm text-gray-500"
                                        >
                                            {query
                                                ? 'Tidak ada item yang cocok dengan pencarian.'
                                                : 'Belum ada item.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {items.total > 0 && (
                        <div className="space-y-2">
                            <p className="text-center text-sm text-gray-500">
                                Menampilkan {items.from}–{items.to} dari {items.total} item
                            </p>
                            <Pagination links={items.links} />
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
