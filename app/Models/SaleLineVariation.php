<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu variasi terpilih pada satu baris jual -- snapshot nama & harga SAAT
 * transaksi (pola identik product_name/member_name_snapshot), sehingga
 * nota lama tetap benar walau variasi itu kemudian di-rename/diubah
 * harganya. `hpp_snapshot` SELALU '0' di Tahap 1 -- lihat docblock
 * migrasinya untuk penjelasan lengkap kenapa kolom ini sudah ada sekarang.
 */
#[Fillable(['sale_line_id', 'variation_id', 'name_snapshot', 'price_snapshot', 'hpp_snapshot'])]
class SaleLineVariation extends Model
{
    protected function casts(): array
    {
        return [
            'price_snapshot' => 'decimal:4',
            'hpp_snapshot' => 'decimal:4',
        ];
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
