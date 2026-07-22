<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Satu-satunya cara resmi membuat akun Admin sungguhan untuk produksi —
 * murni interaktif, password TIDAK PERNAH ditulis ke seeder/.env/git.
 *
 * Aman dijalankan berulang kali di database yang sudah ada: tidak pernah
 * menghapus apa pun. Kalau email sudah terdaftar, menawarkan untuk
 * menjadikan akun itu Admin (+ opsional reset password) alih-alih membuat
 * duplikat.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Buat (atau jadikan Admin) satu akun administrator secara interaktif.';

    public function handle(): int
    {
        $admin = Role::where('name', 'Admin')->first();
        if (! $admin) {
            $this->error('Role "Admin" belum ada — jalankan seeder (RolesAndPermissionsSeeder) dulu sebelum command ini.');

            return self::FAILURE;
        }

        $email = $this->askForEmail();
        if ($email === null) {
            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $this->handleExistingUser($existing, $admin);
        }

        $name = trim((string) $this->ask('Nama admin'));
        if ($name === '') {
            $this->error('Nama tidak boleh kosong.');

            return self::FAILURE;
        }

        $password = $this->askForNewPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role_id' => $admin->id,
        ]);

        $this->info("Admin \"{$user->name}\" ({$user->email}) berhasil dibuat dengan role Admin.");

        return self::SUCCESS;
    }

    /**
     * @return string|null Email tervalidasi, atau null kalau input tidak valid (sudah menampilkan error).
     */
    private function askForEmail(): ?string
    {
        $email = trim((string) $this->ask('Email admin'));

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']]);
        if ($validator->fails()) {
            $this->error('Email tidak valid: '.$validator->errors()->first('email'));

            return null;
        }

        return $email;
    }

    private function handleExistingUser(User $existing, Role $admin): int
    {
        if ($existing->role_id === $admin->id) {
            $this->info("\"{$existing->email}\" sudah menjadi Admin — tidak ada perubahan.");

            return self::SUCCESS;
        }

        $this->warn("User dengan email \"{$existing->email}\" sudah ada (role saat ini: ".($existing->role?->name ?? 'tidak ada').').');
        if (! $this->confirm('Jadikan akun ini Admin?', false)) {
            $this->info('Dibatalkan — tidak ada perubahan.');

            return self::SUCCESS;
        }

        $existing->role_id = $admin->id;

        if ($this->confirm('Reset password akun ini juga?', false)) {
            $password = $this->askForNewPassword();
            if ($password === null) {
                return self::FAILURE;
            }
            $existing->password = $password;
        }

        $existing->save();
        $this->info("\"{$existing->email}\" sekarang menjadi Admin.");

        return self::SUCCESS;
    }

    /**
     * Minta password baru dua kali (ketik tersembunyi via `secret()`),
     * validasi kekuatannya, dan pastikan keduanya sama persis.
     *
     * @return string|null Password tervalidasi, atau null kalau gagal (sudah menampilkan error).
     */
    private function askForNewPassword(): ?string
    {
        $password = $this->secret('Password admin (ketikan tersembunyi, minimal 10 karakter)');

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(10)->letters()->numbers()]],
        );
        if ($validator->fails()) {
            $this->error('Password lemah: '.$validator->errors()->first('password'));

            return null;
        }

        // Daftar hitam kecil untuk password umum yang secara TEKNIS lolos
        // aturan panjang+huruf+angka di atas tapi jelas-jelas lemah (mis.
        // "password123") — pertahanan berlapis, bukan pengganti aturan di
        // atas.
        $commonPasswords = ['password123', 'admin12345', 'qwerty12345', 'passw0rd123', 'letmein123'];
        if (in_array(strtolower((string) $password), $commonPasswords, true)) {
            $this->error('Password terlalu umum/mudah ditebak — gunakan password lain.');

            return null;
        }

        $confirmation = $this->secret('Ketik ulang password untuk konfirmasi');
        if ($password !== $confirmation) {
            $this->error('Password dan konfirmasi tidak sama.');

            return null;
        }

        return $password;
    }
}
