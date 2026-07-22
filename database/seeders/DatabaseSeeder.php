<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database — fondasi murni, TANPA user apa pun.
     * Akun admin sungguhan dibuat terpisah lewat `php artisan admin:create`
     * (interaktif, password tidak pernah masuk seeder/env/git) — lihat
     * `App\Console\Commands\CreateAdminCommand`.
     */
    public function run(): void
    {
        $this->call(FoundationSeeder::class);
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
