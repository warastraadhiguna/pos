import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const formatBytes = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }
    return `${value.toFixed(1)} ${units[unitIndex]}`;
};

/**
 * Daftar backup = hasil scan folder langsung (lihat BackupService::list())
 * -- tidak ada tabel/baris DB di baliknya, jadi tidak ada form create multi
 * field di sini sama sekali, cuma tombol pemicu + daftar + aksi per baris
 * (pola sama KasBank/Accounts/Index.jsx).
 */
export default function Index({ backups }) {
    const [creating, setCreating] = useState(false);

    const createBackup = () => {
        setCreating(true);
        router.post(route('backup.store'), {}, {
            onFinish: () => setCreating(false),
        });
    };

    const destroyBackup = (filename) => {
        if (!confirm(`Hapus backup "${filename}"? Tindakan ini tidak bisa dibatalkan.`)) {
            return;
        }
        router.delete(route('backup.destroy', filename));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Backup Database
                </h2>
            }
        >
            <Head title="Backup Database" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm sm:p-6">
                        <div>
                            <h3 className="font-semibold text-gray-900">Backup Manual</h3>
                            <p className="mt-1 text-xs text-gray-500">
                                Backup otomatis juga berjalan sendiri tiap hari jam 02:30 --
                                tombol ini untuk backup sekarang juga, mis. sebelum perubahan
                                besar.
                            </p>
                        </div>
                        <PrimaryButton onClick={createBackup} disabled={creating}>
                            {creating ? 'Memproses...' : 'Backup Sekarang'}
                        </PrimaryButton>
                    </div>

                    <div className="w-full overflow-x-auto overscroll-x-contain bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nama File
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Ukuran
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Dibuat
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {backups.map((backup) => (
                                    <tr key={backup.filename}>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {backup.filename}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatBytes(backup.size_bytes)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {backup.created_at}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <div className="flex justify-end gap-2">
                                                <a
                                                    href={route('backup.download', backup.filename)}
                                                >
                                                    <SecondaryButton type="button">
                                                        Unduh
                                                    </SecondaryButton>
                                                </a>
                                                <DangerButton
                                                    type="button"
                                                    onClick={() => destroyBackup(backup.filename)}
                                                >
                                                    Hapus
                                                </DangerButton>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {backups.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-6 py-6 text-center text-sm text-gray-500"
                                        >
                                            Belum ada backup. Klik "Backup Sekarang" untuk
                                            membuat yang pertama.
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
