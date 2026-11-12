import InputLabel from '@/Components/InputLabel';
import SelectInput from '@/Components/SelectInput';
import { usePage } from '@inertiajs/react';

/**
 * Dropdown "Cabang" untuk filter halaman riwayat/daftar. Tidak dirender sama
 * sekali kalau Multi-Cabang mati -- toko satu lokasi tidak melihat apa pun
 * yang berubah (pola sama Reports/SalesReport.jsx).
 */
export default function OutletFilter({ outlets, value, onChange, id = 'outlet_id' }) {
    const multiBranchEnabled = usePage().props.featureFlags?.multi_branch_enabled;

    if (!multiBranchEnabled) return null;

    return (
        <div>
            <InputLabel htmlFor={id} value="Cabang" />
            <SelectInput
                id={id}
                className="mt-1 h-10"
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
            >
                <option value="">Semua Cabang</option>
                {outlets.map((outlet) => (
                    <option key={outlet.id} value={outlet.id}>
                        {outlet.name}
                    </option>
                ))}
            </SelectInput>
        </div>
    );
}
