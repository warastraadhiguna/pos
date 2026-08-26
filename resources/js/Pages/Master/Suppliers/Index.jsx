import DangerButton from "@/Components/DangerButton";
import PrimaryButton from "@/Components/PrimaryButton";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, Link, router } from "@inertiajs/react";

export default function Index({ suppliers }) {
    const destroy = (supplier) => {
        if (confirm(`Hapus supplier "${supplier.name}"?`)) {
            router.delete(route("master.suppliers.destroy", supplier.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Supplier
                </h2>
            }
        >
            <Head title="Supplier" />

            <div className="py-6 sm:py-12">
                <div className="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {/* Tombol tambah */}
                    <div className="flex justify-end">
                        <Link href={route("master.suppliers.create")}>
                            <PrimaryButton>Tambah Supplier</PrimaryButton>
                        </Link>
                    </div>

                    {/* Card tabel */}
                    <div className="w-full overflow-x-auto overscroll-x-contain bg-white shadow-sm sm:rounded-lg">
                        {/*
                            Wrapper ini yang membuat tabel
                            bisa di-scroll horizontal di HP.
                        */}
                        <div className="w-full overflow-x-auto overscroll-x-contain">
                            <table className="min-w-[800px] w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="whitespace-nowrap px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"
                                        >
                                            Nama
                                        </th>

                                        <th
                                            scope="col"
                                            className="whitespace-nowrap px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"
                                        >
                                            Telepon
                                        </th>

                                        <th
                                            scope="col"
                                            className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"
                                        >
                                            Alamat
                                        </th>

                                        <th
                                            scope="col"
                                            className="whitespace-nowrap px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500"
                                        >
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {suppliers.map((supplier) => (
                                        <tr
                                            key={supplier.id}
                                            className="hover:bg-gray-50"
                                        >
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {supplier.name}
                                            </td>

                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                                {supplier.phone ?? "-"}
                                            </td>

                                            <td className="min-w-[280px] px-6 py-4 text-sm text-gray-600">
                                                {supplier.address ?? "-"}
                                            </td>

                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                                <div className="flex items-center justify-end gap-4">
                                                    <Link
                                                        href={route(
                                                            "master.suppliers.edit",
                                                            supplier.id,
                                                        )}
                                                        className="font-medium text-primary hover:text-primary-dark"
                                                    >
                                                        Edit
                                                    </Link>

                                                    <DangerButton
                                                        onClick={() =>
                                                            destroy(supplier)
                                                        }
                                                    >
                                                        Hapus
                                                    </DangerButton>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}

                                    {suppliers.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="px-6 py-8 text-center text-sm text-gray-500"
                                            >
                                                Belum ada supplier.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
