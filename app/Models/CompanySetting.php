<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton — selalu satu baris (id=1), di-seed lewat FoundationSeeder.
 * `current()` sengaja pakai firstOrFail() (bukan default diam-diam di
 * kode) supaya baris yang hilang kelihatan jelas sebagai bug seeding,
 * bukan tersamar sebagai "PPN nonaktif" atau sebaliknya.
 */
#[Fillable([
    'ppn_active',
    'product_display_mode',
    'store_name',
    'store_address',
    'store_phone',
    'receipt_footer',
    'show_stock_on_button',
    'show_product_image',
    'payment_quick_amounts',
    'mobile_print_receipt',
    'member_enabled',
    'table_enabled',
    'note_enabled',
    'variation_enabled',
    'draft_enabled',
    'qris_enabled',
    'qris_cash_account_code',
    'device_binding_grace_period_ends_at',
])]
class CompanySetting extends Model
{
    public const PRODUCT_DISPLAY_MODE_ALL = 'all';

    public const PRODUCT_DISPLAY_MODE_SEARCH_ONLY = 'search_only';

    /**
     * Dipakai baik di validasi (SettingController) maupun sebagai fallback
     * kalau kolomnya somehow NULL (baris lama sebelum migrasi ini, atau
     * admin pernah mengosongkan seluruh daftar) -- SATU sumber kebenaran
     * untuk "default yang wajar", tidak diduplikasi di tempat lain.
     */
    public const DEFAULT_PAYMENT_QUICK_AMOUNTS = [5000, 10000, 20000, 50000, 100000];

    public const MAX_PAYMENT_QUICK_AMOUNTS = 8;

    protected function casts(): array
    {
        return [
            'ppn_active' => 'boolean',
            'show_stock_on_button' => 'boolean',
            'show_product_image' => 'boolean',
            'mobile_print_receipt' => 'boolean',
            'member_enabled' => 'boolean',
            'table_enabled' => 'boolean',
            'note_enabled' => 'boolean',
            'variation_enabled' => 'boolean',
            'draft_enabled' => 'boolean',
            'qris_enabled' => 'boolean',
            'device_binding_grace_period_ends_at' => 'datetime',
        ];
    }

    /**
     * Device baru (device_id belum pernah tercatat di tabel `devices`)
     * auto-approved selama masih di dalam jendela ini -- lihat migration
     * `..._add_device_binding_grace_period_to_company_settings_table` untuk
     * alasan lengkap. NULL = grace period mati (device baru selalu masuk
     * pending, perilaku normal jangka panjang).
     */
    public function deviceBindingGracePeriodActive(): bool
    {
        return $this->device_binding_grace_period_ends_at !== null
            && $this->device_binding_grace_period_ends_at->isFuture();
    }

    /**
     * Attribute manual (bukan cast 'array' biasa) supaya bisa sekaligus
     * menjaga jaring pengaman "tidak pernah null/kosong" di SATU tempat --
     * kolom NULL (baris sebelum migrasi ini, atau data rusak) atau JSON
     * kosong `[]` jatuh ke default yang wajar, bukan menyembunyikan tombol
     * di semua pemakai (web & mobile) karena lupa fallback masing-masing.
     * Lewat UI admin sendiri ini tidak akan pernah kosong (SettingController
     * mewajibkan minimal 1 nominal), jadi fallback ini murni jaring
     * pengaman untuk data lama/tidak terduga.
     */
    protected function paymentQuickAmounts(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                $decoded = $value !== null ? json_decode($value, true) : null;

                return (is_array($decoded) && $decoded !== [])
                    ? array_values($decoded)
                    : self::DEFAULT_PAYMENT_QUICK_AMOUNTS;
            },
            set: fn (?array $value) => $value === null ? null : json_encode(array_values($value)),
        );
    }

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }
}
