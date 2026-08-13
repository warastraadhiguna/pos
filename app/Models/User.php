<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role_id', 'outlet_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Sama alasannya dengan `Account::parent_id` -- role_id
            // dibandingkan strict (`===`) terhadap `Role::id` (int) di
            // CreateAdminCommand, rentan bug yang sama kalau driver PDO
            // mengembalikannya sebagai string mentah.
            'role_id' => 'integer',
            'outlet_id' => 'integer',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Cabang tempat user ini bekerja (Multi-Cabang Lapisan 1, kasir web
     * `/kasir` -- lihat rancangan). NULL = tidak ditugaskan ke satu cabang
     * (admin/manajer yang mengawasi banyak cabang, ATAU -- untuk SEMUA
     * user yang sudah ada sebelum fitur ini -- "belum pernah diatur",
     * TIDAK di-enforce di logika mana pun sampai Lapisan 3).
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Role Developer (hidden super-admin, lihat rancangan yang disetujui)
     * BYPASS setiap cek permission tanpa syarat -- superset Admin dijamin
     * secara KODE, bukan dengan menyalin baris pivot permission Admin ke
     * role Developer. Kalau disalin statis, permission bisnis baru yang
     * di-attach ke Admin lewat migrasi nanti (pola yang sudah dipakai,
     * mis. `..._seed_branches_manage_permission.php`) tidak otomatis ikut
     * ke Developer kecuali migrasi itu diingat untuk menyentuh dua role --
     * gampang basi diam-diam. Bypass di sini membuat "superset" berlaku
     * SELALU, tanpa perlu baris pivot permission sama sekali untuk role
     * Developer (boleh kosong).
     */
    public function hasPermission(string $key): bool
    {
        if ($this->role?->is_developer) {
            return true;
        }

        return $this->role?->permissions->contains('key', $key) ?? false;
    }

    /**
     * Dipakai HandleInertiaRequests untuk membagikan daftar permission ke
     * frontend (nav item digerbangi dengan mencocokkan ke array ini) --
     * HARUS ikut bypass sepasang dengan hasPermission() di atas, kalau
     * tidak sidebar Developer akan kosong (backend mengizinkan akses tapi
     * frontend tidak pernah menampilkan link-nya) walau backend sudah benar.
     */
    public function permissionKeys(): array
    {
        if ($this->role?->is_developer) {
            return Permission::pluck('key')->all();
        }

        return $this->role?->permissions->pluck('key')->all() ?? [];
    }
}
