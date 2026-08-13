<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Satu-satunya cara resmi membuat/menandai akun Developer (hidden
 * super-admin, lihat rancangan yang disetujui) -- role ini SENGAJA tidak
 * bisa diberikan lewat UI Kelola Pengguna sama sekali (lihat
 * UserController), murni interaktif di sini, password TIDAK PERNAH
 * ditulis ke seeder/.env/git. Mirror persis `admin:create`.
 *
 * Aman dijalankan berulang kali di database yang sudah ada: tidak pernah
 * menghapus apa pun. Kalau email sudah terdaftar, menawarkan untuk
 * menjadikan akun itu Developer (+ opsional reset password) alih-alih
 * membuat duplikat.
 */
class CreateDeveloperCommand extends Command
{
    protected $signature = 'developer:create';

    protected $description = 'Buat (atau jadikan Developer) satu akun hidden super-admin secara interaktif.';

    public function handle(): int
    {
        $developer = Role::where('name', 'Developer')->where('is_developer', true)->first();
        if (! $developer) {
            $this->error('Role "Developer" belum ada — jalankan migrasi dulu sebelum command ini.');

            return self::FAILURE;
        }

        $email = $this->askForEmail();
        if ($email === null) {
            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $this->handleExistingUser($existing, $developer);
        }

        $name = trim((string) $this->ask('Nama developer'));
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
            'role_id' => $developer->id,
        ]);

        $this->info("Developer \"{$user->name}\" ({$user->email}) berhasil dibuat dengan role Developer.");

        return self::SUCCESS;
    }

    /**
     * @return string|null Email tervalidasi, atau null kalau input tidak valid (sudah menampilkan error).
     */
    private function askForEmail(): ?string
    {
        $email = trim((string) $this->ask('Email developer'));

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']]);
        if ($validator->fails()) {
            $this->error('Email tidak valid: '.$validator->errors()->first('email'));

            return null;
        }

        return $email;
    }

    private function handleExistingUser(User $existing, Role $developer): int
    {
        if ($existing->role_id === $developer->id) {
            $this->info("\"{$existing->email}\" sudah menjadi Developer — tidak ada perubahan.");

            return self::SUCCESS;
        }

        $this->warn("User dengan email \"{$existing->email}\" sudah ada (role saat ini: ".($existing->role?->name ?? 'tidak ada').').');
        if (! $this->confirm('Jadikan akun ini Developer?', false)) {
            $this->info('Dibatalkan — tidak ada perubahan.');

            return self::SUCCESS;
        }

        $existing->role_id = $developer->id;

        if ($this->confirm('Reset password akun ini juga?', false)) {
            $password = $this->askForNewPassword();
            if ($password === null) {
                return self::FAILURE;
            }
            $existing->password = $password;
        }

        $existing->save();
        $this->info("\"{$existing->email}\" sekarang menjadi Developer.");

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
        $password = $this->secret('Password developer (ketikan tersembunyi, minimal 10 karakter)');

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
