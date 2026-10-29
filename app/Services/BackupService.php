<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Fitur Backup Database -- lihat rancangan yang disetujui. SENGAJA tidak
 * ada model/tabel baru: daftar backup = hasil scan `storage_path('backups')`
 * langsung (pola sama "stok selalu dihitung ulang dari stock_movements,
 * tidak pernah di-cache" yang sudah jadi kebiasaan app ini) -- tidak ada
 * risiko baris DB dan file fisik saling tidak sinkron.
 *
 * Folder itu sendiri BUKAN disk Laravel (`public`/`local`) -- sudah ada
 * lebih dulu (dump manual lama), sudah di-gitignore (`/storage/backups`),
 * dan tidak pernah diekspos lewat URL publik -- satu-satunya jalan masuk
 * adalah lewat BackupController yang permission-gated.
 */
class BackupService
{
    /**
     * Huruf/angka/garis bawah/strip + akhiran .sql.gz -- cocok dgn nama yang
     * dihasilkan create() sendiri (`{nama_db}_{Ymd_His}.sql.gz`, nama
     * database boleh mengandung strip, mis. "kantin-gelora").
     */
    private const FILENAME_PATTERN = '/^[\w-]+\.sql\.gz$/';

    /**
     * @return array<int, array{filename: string, size_bytes: int, created_at: string}>
     */
    public function list(): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $rows = array_map(fn (string $path) => [
            'filename' => basename($path),
            'size_bytes' => filesize($path),
            'created_at' => date('Y-m-d H:i:s', filemtime($path)),
        ], glob($directory.DIRECTORY_SEPARATOR.'*.sql.gz') ?: []);

        usort($rows, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return $rows;
    }

    /**
     * Jalankan mysqldump, compress hasilnya (gzencode, ekstensi zlib bawaan
     * PHP -- tidak perlu binari `gzip` terpisah), simpan, buang backup lama
     * (lihat pruneOld()). Mengembalikan nama file yang baru dibuat.
     *
     * Argumen mysqldump SENGAJA array (dikirim ke Process sebagai array,
     * bukan string) -- Symfony Process di baliknya menjalankan proses
     * LANGSUNG tanpa lewat shell sama sekali, jadi aman dari shell-injection
     * apa pun isi kredensialnya.
     *
     * Pakai --socket= (bukan -h/-P) kalau `unix_socket` terisi di koneksi
     * mysql -- pelajaran insiden produksi kantin.gelorasports.com: MariaDB
     * di server itu menutup TCP 127.0.0.1:3306 sama sekali, cuma terima
     * unix socket (lihat install.sh). Tanpa ini, backup otomatis akan gagal
     * diam-diam persis seperti `artisan migrate` dulu sebelum diperbaiki.
     *
     * @throws RuntimeException kalau koneksi default bukan mysql, atau mysqldump gagal.
     */
    public function create(): string
    {
        if (config('database.default') !== 'mysql') {
            throw new RuntimeException('Backup hanya didukung untuk koneksi MySQL/MariaDB.');
        }

        $connection = config('database.connections.mysql');
        $directory = $this->directory();
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $command = [config('backup.mysqldump_path')];

        $socket = $connection['unix_socket'] ?? '';
        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '-h'.$connection['host'];
            $command[] = '-P'.$connection['port'];
        }

        $command[] = '-u'.$connection['username'];
        // -p<password> HARUS satu token gabung (syarat mysqldump) -- dan
        // SENGAJA dilewati total kalau password kosong (umum di dev lokal):
        // `-p` TANPA nilai berarti "tanya password interaktif" bagi
        // mysqldump, yang akan menggantung proses ini sampai timeout,
        // bukan error cepat yang jelas.
        $password = $connection['password'] ?? '';
        if ($password !== '') {
            $command[] = '-p'.$password;
        }
        $command[] = '--single-transaction';
        $command[] = $connection['database'];

        $result = Process::timeout(300)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('mysqldump gagal: '.$result->errorOutput());
        }

        $filename = $connection['database'].'_'.now()->format('Ymd_His').'.sql.gz';
        file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, gzencode($result->output(), 9));

        $this->pruneOld();

        return $filename;
    }

    /**
     * Buang backup (manual maupun otomatis) lebih tua dari
     * `config('backup.retention_days')` hari -- pola sama
     * `deploy/backup.sh`'s `find ... -mtime +N -delete`, versi PHP. Public
     * (dipanggil internal oleh create(), TAPI juga berguna dipanggil
     * berdiri sendiri -- mis. kalau retensi diperpendek, bersihkan tanpa
     * perlu bikin backup baru dulu) & gampang diuji lepas dari mysqldump.
     */
    public function pruneOld(): void
    {
        $threshold = now()->subDays((int) config('backup.retention_days'))->getTimestamp();

        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*.sql.gz') ?: [] as $path) {
            if (filemtime($path) < $threshold) {
                unlink($path);
            }
        }
    }

    /**
     * @throws RuntimeException kalau nama file tidak valid atau tidak ditemukan.
     */
    public function delete(string $filename): void
    {
        $path = $this->safePath($filename);

        if (! file_exists($path)) {
            throw new RuntimeException("Backup [{$filename}] tidak ditemukan.");
        }

        unlink($path);
    }

    /**
     * @throws RuntimeException kalau nama file tidak valid atau tidak ditemukan.
     */
    public function pathForDownload(string $filename): string
    {
        $path = $this->safePath($filename);

        if (! file_exists($path)) {
            throw new RuntimeException("Backup [{$filename}] tidak ditemukan.");
        }

        return $path;
    }

    /**
     * Validasi KERAS nama file sebelum dipakai utk unduh/hapus -- mencegah
     * path traversal (mis. "../../etc/passwd") lewat parameter route
     * {filename}. DUA lapis, bukan redundan: basename() menolak apa pun
     * yang mengandung '/', regex menolak karakter aneh lain yang
     * basename() sendiri tidak masalahkan (mis. ekstensi yang salah).
     *
     * @throws RuntimeException kalau nama file tidak valid.
     */
    private function safePath(string $filename): string
    {
        if (basename($filename) !== $filename || ! preg_match(self::FILENAME_PATTERN, $filename)) {
            throw new RuntimeException("Nama file backup tidak valid: [{$filename}].");
        }

        return $this->directory().DIRECTORY_SEPARATOR.$filename;
    }

    private function directory(): string
    {
        return config('backup.storage_path');
    }
}
