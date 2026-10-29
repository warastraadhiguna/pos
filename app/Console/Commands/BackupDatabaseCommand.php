<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dipanggil BAIK oleh jadwal otomatis (lihat routes/console.php) MAUPUN
 * manual lewat CLI (`php artisan backup:run`) -- berguna utk diuji langsung
 * di server lewat SSH sebelum mengandalkan jadwalnya (pastikan mysqldump +
 * socket config benar di server sungguhan).
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Buat backup database sekarang (mysqldump, dikompres, disimpan di storage/backups).';

    public function handle(BackupService $backups): int
    {
        try {
            $filename = $backups->create();
        } catch (Throwable $e) {
            $this->error("Backup gagal: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Backup berhasil: {$filename}");

        return self::SUCCESS;
    }
}
