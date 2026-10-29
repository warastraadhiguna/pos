<?php

namespace Tests\Feature;

use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * BackupService dites terhadap folder TERISOLASI (config('backup.storage_path')
 * diarahkan ke folder sementara, lihat setUp/tearDown) -- SENGAJA TIDAK PERNAH
 * menyentuh `storage/backups` asli (ada dump manual lama di sana).
 */
class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupService $backups;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        config(['backup.storage_path' => $this->tempDir]);

        $this->backups = new BackupService();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);

        parent::tearDown();
    }

    public function test_list_is_empty_when_no_backups_exist(): void
    {
        $this->assertSame([], $this->backups->list());
    }

    public function test_list_returns_backups_newest_first_with_size(): void
    {
        $this->makeFakeBackup('old.sql.gz', 'x', daysAgo: 5);
        $this->makeFakeBackup('new.sql.gz', 'yy', daysAgo: 0);

        $rows = $this->backups->list();

        $this->assertCount(2, $rows);
        $this->assertSame('new.sql.gz', $rows[0]['filename']);
        $this->assertSame(2, $rows[0]['size_bytes']);
        $this->assertSame('old.sql.gz', $rows[1]['filename']);
    }

    public function test_prune_old_deletes_backups_older_than_retention_but_keeps_recent_ones(): void
    {
        config(['backup.retention_days' => 14]);
        $this->makeFakeBackup('ancient.sql.gz', 'x', daysAgo: 20);
        $this->makeFakeBackup('recent.sql.gz', 'x', daysAgo: 2);

        $this->backups->pruneOld();

        $filenames = array_column($this->backups->list(), 'filename');
        $this->assertNotContains('ancient.sql.gz', $filenames);
        $this->assertContains('recent.sql.gz', $filenames);
    }

    /**
     * (a) Inti pertahanan path traversal: nama file dengan '/' di dalamnya
     * (termasuk "../") HARUS ditolak sebelum pernah menyentuh filesystem.
     */
    public function test_delete_rejects_filenames_containing_path_separators(): void
    {
        $this->expectException(RuntimeException::class);

        $this->backups->delete('../../etc/passwd');
    }

    public function test_download_path_rejects_filenames_containing_path_separators(): void
    {
        $this->expectException(RuntimeException::class);

        $this->backups->pathForDownload('../../../etc/passwd');
    }

    public function test_delete_rejects_filenames_with_the_wrong_extension(): void
    {
        $this->makeFakeBackup('not-a-backup.txt', 'x', daysAgo: 0);

        $this->expectException(RuntimeException::class);

        $this->backups->delete('not-a-backup.txt');
    }

    public function test_delete_rejects_nonexistent_but_otherwise_valid_filename(): void
    {
        $this->expectException(RuntimeException::class);

        $this->backups->delete('tidak-pernah-ada.sql.gz');
    }

    public function test_delete_removes_a_real_backup_file(): void
    {
        $this->makeFakeBackup('hapus-ini.sql.gz', 'x', daysAgo: 0);

        $this->backups->delete('hapus-ini.sql.gz');

        $this->assertSame([], $this->backups->list());
    }

    /**
     * End-to-end SUNGGUHAN: mysqldump + gzip terhadap database test MySQL
     * (`pos_akuntansi_test`, sungguhan karena phpunit.xml memang pakai mysql,
     * bukan sqlite) -- di-skip kalau binary mysqldump di
     * config('backup.mysqldump_path') tidak ditemukan di mesin yang
     * menjalankan test, supaya suite tetap portable (mis. CI tanpa mysqldump
     * terpasang, atau developer lain yang belum mengatur .env-nya).
     */
    public function test_create_produces_a_real_gzipped_sql_dump_of_the_test_database(): void
    {
        if (config('database.default') !== 'mysql' || ! is_string(config('backup.mysqldump_path')) || ! file_exists(config('backup.mysqldump_path'))) {
            $this->markTestSkipped('mysqldump tidak ditemukan di mesin ini -- lihat BACKUP_MYSQLDUMP_PATH di .env.');
        }

        $filename = $this->backups->create();

        $this->assertStringEndsWith('.sql.gz', $filename);
        $fullPath = $this->tempDir.DIRECTORY_SEPARATOR.$filename;
        $this->assertFileExists($fullPath);

        $decompressed = gzdecode((string) file_get_contents($fullPath));
        $this->assertNotFalse($decompressed, 'Hasil backup harus gzip yang valid.');
        // Dump SQL sungguhan dari migrasi test (mis. tabel `sales`,
        // `journals`) harus ikut muncul -- bukti ini bukan cuma file
        // kosong/acak yang kebetulan lolos gzencode.
        $this->assertStringContainsString('CREATE TABLE', $decompressed);
        $this->assertStringContainsString('`sales`', $decompressed);
    }

    private function makeFakeBackup(string $filename, string $contents, int $daysAgo): void
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $contents);
        touch($path, now()->subDays($daysAgo)->getTimestamp());
    }
}
