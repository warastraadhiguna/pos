<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Daftar teks "isi cepat" untuk catatan per-item/per-transaksi -- murni
 * alat bantu ketik, TIDAK direferensikan oleh sales/sale_lines lewat FK
 * apa pun (lihat docblock migrasi `add_note_to_sales_table`). Menghapus
 * baris ini tidak pernah memengaruhi nota yang sudah tersimpan, karena
 * teksnya sudah disalin apa adanya ke sales.note/sale_lines.note saat
 * kasir memilihnya -- beda prinsip dari Member/DiningTable yang memang
 * sengaja dilacak baliknya lewat member_id/table_id.
 */
#[Fillable(['text', 'is_active'])]
class NoteTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
