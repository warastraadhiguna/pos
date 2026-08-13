import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

// Halaman ini SENGAJA tidak dicantumkan di navGroups (AuthenticatedLayout.jsx)
// -- lihat routes/web.php + docs/DEVICE_BINDING.md untuk URL-nya. Tetap
// digerbangi permission:devices.manage seperti halaman admin lain manapun.
const formatDateTimeWIB = (iso) => {
    if (!iso) return '-';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(iso));
    const get = (type) => parts.find((p) => p.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')} WIB`;
};

const statusBadge = {
    pending: (
        <span className="rounded-full bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800">
            Menunggu Persetujuan
        </span>
    ),
    approved: (
        <span className="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">
            Disetujui
        </span>
    ),
    revoked: (
        <span className="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700">
            Dicabut
        </span>
    ),
};

export default function Index({ devices, outlets }) {
    const approve = (device) => {
        router.put(route('devices.approve', device.id), {}, { preserveScroll: true });
    };

    const revoke = (device) => {
        if (!confirm(`Cabut akses perangkat [${device.device_id}]? Perangkat ini akan langsung tidak bisa login/transaksi kalau sedang online, atau paling lambat dalam 7 hari kalau sedang offline.`)) {
            return;
        }
        router.put(route('devices.revoke', device.id), {}, { preserveScroll: true });
    };

    // Multi-Cabang Lapisan 1 -- device = sumber utama resolusi cabang untuk
    // transaksi mobile nanti (Lapisan 3). Belum di-enforce di sini, cuma
    // penugasan -- lihat DeviceService::assignOutlet().
    const assignOutlet = (device, outletId) => {
        router.put(
            route('devices.assign-outlet', device.id),
            { outlet_id: outletId || null },
            { preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Kelola Perangkat
                </h2>
            }
        >
            <Head title="Kelola Perangkat" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">
                        Perangkat mobile yang pernah mencoba login. Perangkat
                        BARU otomatis disetujui kalau grace period aktif
                        (lihat Pengaturan &gt; Device Binding — Grace
                        Period); di luar itu, perangkat baru menunggu
                        persetujuan di sini sebelum bisa login/transaksi.
                    </p>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Device ID
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Label
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Status
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Terakhir Terlihat
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Didaftarkan Oleh
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Cabang
                                    </th>
                                    <th className="px-6 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {devices.map((device) => (
                                    <tr key={device.id}>
                                        <td className="whitespace-nowrap px-6 py-4 font-mono text-xs text-gray-600">
                                            {device.device_id}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {device.label ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            {statusBadge[device.status] ?? device.status}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {formatDateTimeWIB(device.last_seen_at)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                            {device.registered_by?.name ?? '-'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-sm">
                                            <SelectInput
                                                className="h-9"
                                                value={device.outlet?.id ?? ''}
                                                onChange={(e) => assignOutlet(device, e.target.value)}
                                            >
                                                <option value="">— Belum diatur —</option>
                                                {outlets.map((outlet) => (
                                                    <option key={outlet.id} value={outlet.id}>
                                                        {outlet.name}
                                                    </option>
                                                ))}
                                            </SelectInput>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <div className="flex justify-end gap-2">
                                                {device.status !== 'approved' && (
                                                    <SecondaryButton onClick={() => approve(device)}>
                                                        Setujui
                                                    </SecondaryButton>
                                                )}
                                                {device.status !== 'revoked' && (
                                                    <SecondaryButton onClick={() => revoke(device)}>
                                                        Cabut
                                                    </SecondaryButton>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {devices.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-6 py-6 text-center text-sm text-gray-500">
                                            Belum ada perangkat yang pernah mencoba login.
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
