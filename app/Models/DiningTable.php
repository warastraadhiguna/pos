<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nomor/nama meja. Class ini sengaja BUKAN bernama `Table` -- selain
 * terasa generik, itu bentrok langsung dengan widget `Table` bawaan
 * `package:flutter/material.dart` di sisi mobile, jadi `DiningTable`
 * dipakai konsisten di kedua sisi walau nama tabel SQL-nya sendiri
 * tetap `tables` (lihat migrasi).
 *
 * Sengaja TIDAK ada kolom/field status (terisi/kosong) -- keputusan itu
 * belum diambil sama sekali (bisa jadi kolom di tabel ini nanti, atau
 * dihitung dari transaksi draft aktif begitu fitur draft/rangkaian ke-5
 * dibangun) -- model ini tidak mengunci arah mana pun.
 */
#[Fillable(['name', 'capacity', 'area', 'is_active'])]
class DiningTable extends Model
{
    protected $table = 'tables';

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'table_id');
    }
}
