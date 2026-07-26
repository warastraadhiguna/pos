<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'type', 'normal_balance', 'parent_id', 'is_active'])]
class Account extends Model
{
    protected function casts(): array
    {
        return [
            // Beberapa driver PDO/MySQL mengembalikan kolom integer biasa
            // (bukan primary key -- Eloquent sudah otomatis meng-cast
            // primary key ke int, tapi TIDAK untuk foreign key seperti ini)
            // sebagai string mentah ("6", bukan 6). Tanpa cast eksplisit
            // ini, perbandingan strict (`===`/`!==`) terhadap id (int) bisa
            // salah di environment tertentu walau datanya benar -- persis
            // penyebab bug "Akun [1-1000] bukan akun Kas/Bank yang aktif"
            // di produksi (CashAccountService::assertValidCashAccount()
            // membandingkan parent_id dengan groupHeaderId()).
            'parent_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'inventory_account_id');
    }

    public function outputTaxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'output_account_id');
    }

    public function inputTaxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'input_account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
