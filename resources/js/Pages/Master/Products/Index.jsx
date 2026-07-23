import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import ProductImage from '@/Components/ProductImage';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');

export default function Index({ products }) {
    const destroy = (product) => {
        if (confirm(`Hapus produk "${product.name}"?`)) {
            router.delete(route('master.products.destroy', product.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Produk
                </h2>
            }
        >
            <Head title="Produk" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">
                        Produk adalah yang dijual ke pelanggan di Kasir (mis. "Kopi Susu Panas"). Tiap produk
                        disusun dari satu atau lebih Item lewat kolom "Komponen BOM" — stok yang berkurang saat
                        terjual adalah stok Item, bukan produk itu sendiri.
                    </p>
                    <div className="flex justify-end">
                        <Link href={route('master.products.create')}>
                            <PrimaryButton>Tambah Produk</PrimaryButton>
                        </Link>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3" />
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nama
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Kategori
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Barcode
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Harga Jual
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Pajak
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Komponen BOM
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {products.map((product) => (
                                    <tr key={product.id}>
                                        <td className="whitespace-nowrap px-6 py-4">
                                            <ProductImage
                                                src={product.image_url}
                                                name={product.name}
                                                className="h-10 w-10 text-sm"
                                            />
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {product.name}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {product.product_category?.name ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-600">
                                            {product.barcode ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatRupiah(product.sell_price)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {product.tax_rate?.name ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {product.components_count} item
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            <span
                                                className={
                                                    product.is_active
                                                        ? 'text-green-700'
                                                        : 'text-gray-400'
                                                }
                                            >
                                                {product.is_active
                                                    ? 'Aktif'
                                                    : 'Nonaktif'}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <Link
                                                href={route(
                                                    'master.products.edit',
                                                    product.id,
                                                )}
                                                className="text-primary hover:text-primary-dark"
                                            >
                                                Edit
                                            </Link>
                                            <DangerButton
                                                className="ms-4"
                                                onClick={() =>
                                                    destroy(product)
                                                }
                                            >
                                                Hapus
                                            </DangerButton>
                                        </td>
                                    </tr>
                                ))}
                                {products.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-6 py-4 text-center text-sm text-gray-500"
                                        >
                                            Belum ada produk.
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
