<?php

return [

    /*
    |--------------------------------------------------------------------------
    | mysqldump binary
    |--------------------------------------------------------------------------
    |
    | Path absolut -- pola sama PHP_BIN="/usr/bin/php8.4" di deploy/install.sh:
    | proses PHP-FPM yang menjalankan backup lewat web (bukan shell interaktif)
    | bisa punya PATH yang lebih terbatas, jadi jangan andalkan "mysqldump"
    | polos ada di PATH. Override lewat .env (BACKUP_MYSQLDUMP_PATH) untuk
    | lingkungan yang path-nya beda (mis. WAMP di Windows saat uji coba lokal).
    |
    */
    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', '/usr/bin/mysqldump'),

    /*
    |--------------------------------------------------------------------------
    | Retensi backup
    |--------------------------------------------------------------------------
    |
    | Backup (baik manual maupun otomatis) lebih tua dari ini (hari) dibuang
    | otomatis setiap kali backup baru dibuat -- pola sama deploy/backup.sh.
    |
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Folder penyimpanan
    |--------------------------------------------------------------------------
    |
    | Default `storage/backups` -- sudah ada sebelum fitur ini (dump manual
    | lama), sudah di-gitignore (`/storage/backups`). Dibuat bisa diatur
    | (bukan storage_path('backups') langsung di kode) supaya test bisa
    | memakai folder terisolasi, tidak menyentuh backup asli di lokal.
    |
    */
    'storage_path' => env('BACKUP_STORAGE_PATH', storage_path('backups')),

];
