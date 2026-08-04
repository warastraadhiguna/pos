<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Draft (nota belum final) yang disinkronkan lintas-device -- lihat
 * docblock migrasinya untuk rancangan lengkap (status, soft-lock,
 * per-item merge). SEMUA logika push/pull/hold/release hidup di
 * `DraftSyncService` (principle #2 -- business logic di service, bukan
 * di controller/model).
 */
#[Fillable([
    'outlet_id',
    'local_uuid',
    'status',
    'created_by_user_id',
    'member_id',
    'member_name_snapshot',
    'table_id',
    'table_name_snapshot',
    'note',
    'held_by_user_id',
    'held_by_device_label',
    'held_at',
])]
class Draft extends Model
{
    protected function casts(): array
    {
        return [
            'held_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }

    public function heldByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DraftLine::class);
    }

    /**
     * Timeout soft-lock -- lihat DraftSyncService::hold() untuk mekanisme
     * klaim/ambil-alih lengkap. 5 menit (keputusan user): draft yang
     * dipegang tapi tidak ada aktivitas (push konten baru) selama ini
     * dianggap ditinggal (HP mati/offline permanen) dan boleh direbut
     * device lain TANPA campur tangan admin.
     */
    public const LOCK_TIMEOUT_MINUTES = 5;

    /**
     * True kalau draft ini SEDANG dipegang device lain, secara efektif --
     * memperhitungkan timeout (lock basi dianggap TIDAK terpegang lagi,
     * lihat konstanta di atas). `null`/`$excludingUserId` cocok berarti
     * "siapa pun boleh anggap ini tidak terpegang", dipakai `hold()` untuk
     * membiarkan pemegang yang sama meng-hold ulang (refresh) draftnya
     * sendiri tanpa dianggap konflik.
     */
    public function isHeldByOther(?int $excludingUserId): bool
    {
        if ($this->held_by_user_id === null) {
            return false;
        }
        if ($excludingUserId !== null && $this->held_by_user_id === $excludingUserId) {
            return false;
        }

        return $this->held_at !== null
            && $this->held_at->greaterThan(now()->subMinutes(self::LOCK_TIMEOUT_MINUTES));
    }
}
