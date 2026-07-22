<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit perubahan saklar PPN — satu baris per perubahan NILAI lewat
 * `SettingController::updatePpn()`, bukan tiap submit form (submit ulang
 * nilai yang sama tidak menghasilkan baris baru). Immutable: tidak pernah
 * di-update/delete setelah dibuat.
 */
#[Fillable(['ppn_active', 'changed_by_user_id'])]
class CompanySettingLog extends Model
{
    protected function casts(): array
    {
        return [
            'ppn_active' => 'boolean',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
