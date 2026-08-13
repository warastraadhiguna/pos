<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit perubahan saklar berstatus tinggi — satu baris per perubahan
 * NILAI (bukan tiap submit form, submit ulang nilai yang sama tidak
 * menghasilkan baris baru). Immutable: tidak pernah di-update/delete
 * setelah dibuat.
 *
 * `setting_key` membedakan setting mana yang berubah pada baris ini
 * ('ppn_active' | 'multi_branch_enabled' | 'device_binding_grace_period')
 * -- cuma KOLOM yang sesuai `setting_key` itu yang terisi, sisanya null.
 * Baris lama (sebelum kolom ini ada) tidak punya `setting_key` -- selalu
 * berarti 'ppn_active', satu-satunya setting yang pernah dicatat saat itu.
 * Role Developer (lihat rancangan yang disetujui) menaikkan
 * `multi_branch_enabled`/`device_binding_grace_period` jadi tindakan
 * berakses-tinggi -- makanya sekarang ikut dicatat di sini, bukan cuma PPN.
 */
#[Fillable(['setting_key', 'ppn_active', 'multi_branch_enabled', 'device_binding_grace_period_ends_at', 'changed_by_user_id'])]
class CompanySettingLog extends Model
{
    protected function casts(): array
    {
        return [
            'ppn_active' => 'boolean',
            'multi_branch_enabled' => 'boolean',
            'device_binding_grace_period_ends_at' => 'datetime',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
