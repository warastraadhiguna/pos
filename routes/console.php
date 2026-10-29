<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fitur Backup Database -- task terjadwal PERTAMA di app ini. Server yang
// sudah punya cron `* * * * * php8.4 artisan schedule:run` (lihat
// deploy/install.sh) otomatis menjalankan ini tiap hari TANPA entry cron
// baru -- tapi itu cron perlu dipastikan ADA dulu di server yang
// bersangkutan (sudah ada di kantin.gelorasports.com, belum terkonfirmasi
// di wedangan-sante).
Schedule::command('backup:run')->daily()->at('02:30');
