<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Mengingat filter daftar (tanggal/cari) terakhir per halaman di sesi
 * login, supaya setelah simpan data (redirect ke index tanpa query) atau
 * klik "Kembali" dari form, daftar tetap memakai filter yang sebelumnya
 * dipilih pengguna -- bukan kembali ke default (hari ini).
 */
trait RemembersListFilters
{
    /**
     * @param  array<string, mixed>  $filters  hasil validate() dari request ini
     * @param  list<string>  $keys  nama parameter filter halaman ini
     * @return array<string, mixed>
     */
    protected function rememberListFilters(Request $request, string $page, array $filters, array $keys = ['date_from', 'date_to', 'search']): array
    {
        $sessionKey = "list_filters.{$page}";

        if ($request->hasAny($keys)) {
            $request->session()->put($sessionKey, $filters);

            return $filters;
        }

        return $request->session()->get($sessionKey, $filters);
    }
}
