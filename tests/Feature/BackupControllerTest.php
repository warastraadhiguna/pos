<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pola `actingAsUserWithPermissions()` PERSIS
 * tests/Feature/Kasir/SaleVoidControllerTest.php. Folder backup
 * diisolasi (lihat setUp/tearDown) -- pola sama BackupServiceTest, TIDAK
 * PERNAH menyentuh `storage/backups` asli.
 *
 * Codebase ini SENGAJA tidak memakai mocking di mana pun (dikonfirmasi:
 * nol `mock()` di seluruh tests/) -- test di sini juga memanggil
 * BackupService::create() SUNGGUHAN lewat HTTP (bukan stub), dengan guard
 * skip yang sama seperti BackupServiceTest kalau mysqldump tidak
 * ditemukan di mesin ini. Kegagalan mysqldump diuji TANPA butuh binary
 * sama sekali -- cukup ganti koneksi default jadi bukan mysql, yang
 * membuat BackupService::create() menolak lebih dulu secara deterministik.
 */
class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup_controller_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        config(['backup.storage_path' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);

        parent::tearDown();
    }

    private function actingAsUserWithPermissions(array $keys): User
    {
        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $role->permissions()->attach(
            collect($keys)->map(fn (string $key) => Permission::create(['key' => $key, 'label' => $key, 'group' => 'Test'])->id),
        );
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        return $user;
    }

    private function makeFakeBackup(string $filename, string $contents = 'isi palsu'): void
    {
        file_put_contents($this->tempDir.DIRECTORY_SEPARATOR.$filename, $contents);
    }

    public function test_index_without_permission_is_forbidden(): void
    {
        $this->actingAsUserWithPermissions(['kasir.access']);

        $this->get(route('backup.index'))->assertForbidden();
    }

    public function test_store_without_permission_is_forbidden(): void
    {
        $this->actingAsUserWithPermissions(['kasir.access']);

        $this->post(route('backup.store'))->assertForbidden();
    }

    public function test_download_without_permission_is_forbidden(): void
    {
        $this->actingAsUserWithPermissions(['kasir.access']);
        $this->makeFakeBackup('rahasia.sql.gz');

        $this->get(route('backup.download', 'rahasia.sql.gz'))->assertForbidden();
    }

    public function test_destroy_without_permission_is_forbidden(): void
    {
        $this->actingAsUserWithPermissions(['kasir.access']);
        $this->makeFakeBackup('jangan-hapus.sql.gz');

        $this->delete(route('backup.destroy', 'jangan-hapus.sql.gz'))->assertForbidden();
        $this->assertFileExists($this->tempDir.DIRECTORY_SEPARATOR.'jangan-hapus.sql.gz');
    }

    public function test_index_lists_existing_backups_for_a_permitted_user(): void
    {
        $this->actingAsUserWithPermissions(['backup.manage']);
        $this->makeFakeBackup('satu.sql.gz');

        $response = $this->get(route('backup.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('backups', 1)
            ->where('backups.0.filename', 'satu.sql.gz'));
    }

    public function test_store_creates_a_real_backup_when_permitted(): void
    {
        if (config('database.default') !== 'mysql' || ! is_string(config('backup.mysqldump_path')) || ! file_exists(config('backup.mysqldump_path'))) {
            $this->markTestSkipped('mysqldump tidak ditemukan di mesin ini -- lihat BACKUP_MYSQLDUMP_PATH di .env.');
        }

        $this->actingAsUserWithPermissions(['backup.manage']);

        $response = $this->post(route('backup.store'));

        $response->assertRedirect(route('backup.index'));
        $response->assertSessionHas('success');
        $this->assertCount(1, glob($this->tempDir.DIRECTORY_SEPARATOR.'*.sql.gz'));
    }

    public function test_store_surfaces_a_graceful_error_when_backup_fails(): void
    {
        $this->actingAsUserWithPermissions(['backup.manage']);

        // Memaksa BackupService::create() gagal secara DETERMINISTIK tanpa
        // butuh mysqldump sungguhan -- arahkan ke binary yang tidak ada,
        // Process::run() gagal start. SENGAJA TIDAK menyentuh
        // `database.default`: app ini pakai session & cache lewat koneksi
        // DB default (lihat .env), jadi mengganti koneksi default di
        // tengah request akan merusak sesi/auth request itu sendiri, bukan
        // cuma BackupService::create().
        config(['backup.mysqldump_path' => $this->tempDir.DIRECTORY_SEPARATOR.'mysqldump-tidak-ada']);

        $response = $this->post(route('backup.store'));

        $response->assertRedirect(route('backup.index'));
        $response->assertSessionHas('error');
        $this->assertSame([], glob($this->tempDir.DIRECTORY_SEPARATOR.'*.sql.gz'));
    }

    public function test_download_streams_an_existing_backup(): void
    {
        $this->actingAsUserWithPermissions(['backup.manage']);
        $this->makeFakeBackup('unduh-ini.sql.gz', 'isi dump palsu');

        $response = $this->get(route('backup.download', 'unduh-ini.sql.gz'));

        $response->assertOk();
        $response->assertDownload('unduh-ini.sql.gz');
    }

    public function test_download_of_nonexistent_backup_redirects_with_error_instead_of_crashing(): void
    {
        $this->actingAsUserWithPermissions(['backup.manage']);

        $response = $this->get(route('backup.download', 'tidak-ada.sql.gz'));

        $response->assertRedirect(route('backup.index'));
        $response->assertSessionHas('error');
    }

    /**
     * (b) Pertahanan berlapis: walau Laravel sendiri sudah menolak '/' di
     * parameter route {filename} (segmen tunggal), nama file BERSIH
     * (tanpa '/') tapi ekstensi salah tetap harus ditolak oleh validasi
     * BackupService, bukan dilayani begitu saja.
     */
    public function test_download_rejects_a_filename_with_the_wrong_extension(): void
    {
        $this->actingAsUserWithPermissions(['backup.manage']);

        $response = $this->get(route('backup.download', 'bukan-backup.php'));

        $response->assertRedirect(route('backup.index'));
        $response->assertSessionHas('error');
    }

    public function test_destroy_removes_an_existing_backup(): void
    {
        $this->actingAsUserWithPermissions(['backup.manage']);
        $this->makeFakeBackup('hapus-ini.sql.gz');

        $response = $this->delete(route('backup.destroy', 'hapus-ini.sql.gz'));

        $response->assertRedirect(route('backup.index'));
        $response->assertSessionHas('success');
        $this->assertFileDoesNotExist($this->tempDir.DIRECTORY_SEPARATOR.'hapus-ini.sql.gz');
    }

    public function test_destroy_rejects_a_filename_with_the_wrong_extension(): void
    {
        $this->actingAsUserWithPermissions(['backup.manage']);
        $this->makeFakeBackup('jangan-kena.txt');

        $response = $this->delete(route('backup.destroy', 'jangan-kena.txt'));

        $response->assertSessionHas('error');
        $this->assertFileExists($this->tempDir.DIRECTORY_SEPARATOR.'jangan-kena.txt');
    }
}
