<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Multi-Cabang Lapisan 1 -- lihat rancangan yang disetujui. Satu-satunya
 * tempat yang menegakkan invarian "maksimal (dan minimal, begitu ada
 * cabang sama sekali) SATU outlet berstatus headquarters" -- sengaja
 * bukan DB constraint (partial unique index tidak dipakai di mana pun di
 * proyek ini), pola sama `DeviceService`/`CashAccountService`: validasi
 * di Service layer, bukan controller atau constraint kaku.
 */
class BranchService
{
    /**
     * Buat cabang baru + SATU warehouse pendamping otomatis (nama "Gudang
     * {nama cabang}") dalam transaksi yang sama -- simplifikasi Layer 1:
     * 1 cabang = 1 gudang untuk sekarang (skema tetap mendukung banyak
     * gudang per cabang nanti, cuma UI ini tidak mengeksposnya).
     *
     * @throws InvalidArgumentException kalau `is_headquarters` true tapi ada masalah (tidak pernah terjadi di create -- lihat setHeadquarters()).
     */
    public function createOutlet(array $data): Outlet
    {
        return DB::transaction(function () use ($data) {
            $isHeadquarters = $data['is_headquarters'] ?? false;
            unset($data['is_headquarters']);
            $identity = $this->pullReceiptIdentity($data);

            $outlet = Outlet::create([...$data, 'is_headquarters' => false]);

            Warehouse::create([
                'outlet_id' => $outlet->id,
                'name' => "Gudang {$outlet->name}",
            ]);

            if ($isHeadquarters) {
                $this->setHeadquarters($outlet);
            }

            $outlet = $outlet->fresh();
            $this->saveReceiptIdentityIfHeadquarters($outlet, $identity);

            return $outlet;
        });
    }

    /**
     * `is_headquarters: false` pada outlet yang SAAT INI headquarters
     * DITOLAK -- tidak boleh ada nol headquarters begitu minimal satu
     * cabang pernah dijadikan pusat. Pindahkan status pusat ke cabang LAIN
     * (checklist `is_headquarters: true` di situ) alih-alih melepasnya
     * langsung di sini.
     *
     * @throws InvalidArgumentException
     */
    public function updateOutlet(Outlet $outlet, array $data): Outlet
    {
        return DB::transaction(function () use ($outlet, $data) {
            $wantsHeadquarters = $data['is_headquarters'] ?? $outlet->is_headquarters;
            unset($data['is_headquarters']);
            $identity = $this->pullReceiptIdentity($data);

            if ($outlet->is_headquarters && ! $wantsHeadquarters) {
                throw new InvalidArgumentException(
                    'Harus ada satu cabang pusat -- pindahkan status pusat ke cabang lain dulu (centang "Cabang Pusat" di cabang itu), baru cabang ini bisa dilepas dari status pusat.',
                );
            }

            $outlet->update($data);

            if ($wantsHeadquarters && ! $outlet->is_headquarters) {
                $this->setHeadquarters($outlet);
            }

            $outlet = $outlet->fresh();
            $this->saveReceiptIdentityIfHeadquarters($outlet, $identity);

            return $outlet;
        });
    }

    /**
     * Lepas status headquarters dari SEMUA outlet lain, lalu tandai
     * `$outlet` -- jaminan "maksimal satu pusat" berlaku SETIAP kali
     * method ini dipanggil, bukan cuma diasumsikan oleh pemanggil.
     */
    public function setHeadquarters(Outlet $outlet): void
    {
        DB::transaction(function () use ($outlet) {
            Outlet::where('id', '!=', $outlet->id)->update(['is_headquarters' => false]);
            $outlet->update(['is_headquarters' => true]);
        });
    }

    /**
     * Multi-Cabang Lapisan 3 -- SATU-SATUNYA tempat "cabang mana yang
     * sedang bertransaksi" diputuskan. Menggantikan 4 titik yang dulu
     * hardcode `Outlet::firstOrFail()` (Kasir\SaleController x2,
     * Api\SaleController, DraftSyncService::push()).
     *
     * Urutan resolusi (lihat rancangan yang disetujui):
     * 1. `multi_branch_enabled=false` -> SELALU pusat, titik. Ini menjamin
     *    kompatibilitas: toko satu lokasi (default) berperilaku IDENTIK
     *    dengan sebelum Multi-Cabang ada, apa pun isi device.outlet_id/
     *    user.outlet_id kebetulan sudah berupa apa.
     * 2. `$deviceId` (Android ID dari `personal_access_tokens.device_id`,
     *    Device Binding) -> kalau device itu terdaftar DAN
     *    `device.outlet_id` terisi, pakai itu -- device fisik yang paling
     *    otoritatif tahu di cabang mana ia berada (mobile).
     * 3. `$user->outlet_id` -- fallback untuk kasir web (`/kasir`, sesi
     *    browser, tidak ada konsep device fisik) atau mobile yang device-nya
     *    belum ditugaskan ke cabang mana pun.
     * 4. Tidak ada satu pun di atas yang cocok (admin/manajer lintas
     *    cabang, atau device/user yang sengaja `outlet_id=NULL`) -> pusat.
     *
     * TIDAK PERNAH melempar exception -- selalu ada jawaban (pusat sebagai
     * dasar teraman), konsisten "jangan sampai transaksi gagal" yang sudah
     * jadi prinsip berulang di Lapisan 1/2.
     */
    public function resolveCurrentOutlet(User $user, ?string $deviceId = null): Outlet
    {
        if (! CompanySetting::current()->multi_branch_enabled) {
            return $this->headquarters();
        }

        if ($deviceId !== null) {
            $device = Device::where('device_id', $deviceId)->first();
            if ($device?->outlet_id) {
                return $device->outlet;
            }
        }

        if ($user->outlet_id) {
            return $user->outlet;
        }

        return $this->headquarters();
    }

    public function headquarters(): Outlet
    {
        return Outlet::where('is_headquarters', true)->firstOrFail();
    }

    /**
     * Identitas struk (header) untuk `$outlet` -- lihat rancangan Identitas
     * per-cabang yang disetujui. Dipakai baik oleh struk web
     * (`SaleHistoryController::receipt()`, resolve dari `$sale->outlet`)
     * maupun sync mobile (`Api\ProductController::index()`, resolve dari
     * outlet device saat ini) -- SATU tempat, supaya kedua kanal tidak
     * bisa menyimpang.
     *
     * Cabang Pusat (`is_headquarters`) SELALU pakai `company_settings`
     * global, TIDAK PERNAH kolom identitasnya sendiri walau terisi --
     * jaminan kompatibilitas: sebelum fitur ini ada, SATU-SATUNYA
     * identitas yang pernah dipakai adalah `company_settings` (diedit
     * lewat Pengaturan > Identitas Toko), dan toko satu-lokasi/`
     * multi_branch_enabled=false` SELALU resolve ke pusat
     * (`resolveCurrentOutlet()`) -- kalau pusat ikut fallback per-field
     * biasa, `outlet.name` ("Outlet Pusat", SELALU terisi sejak dibuat)
     * akan menggantikan `store_name` secara diam-diam, pecah kompatibilitas.
     *
     * Cabang lain: per-field, nilai cabang sendiri kalau terisi, else
     * global -- cabang baru yang belum sempat diisi otomatis dapat
     * identitas pusat/global, tidak pernah struk tanpa identitas sama
     * sekali.
     *
     * @return array{name: ?string, address: ?string, phone: ?string, receipt_footer: ?string}
     */
    public function resolveReceiptIdentity(Outlet $outlet): array
    {
        $global = CompanySetting::current();

        if ($outlet->is_headquarters) {
            return [
                'name' => $global->store_name,
                'address' => $global->store_address,
                'phone' => $global->store_phone,
                'receipt_footer' => $global->receipt_footer,
            ];
        }

        return [
            'name' => $outlet->name ?: $global->store_name,
            'address' => $outlet->address ?: $global->store_address,
            'phone' => $outlet->phone ?: $global->store_phone,
            'receipt_footer' => $outlet->receipt_footer ?: $global->receipt_footer,
        ];
    }

    /**
     * Satukan pengaturan identitas (lihat rancangan yang disetujui) --
     * `store_name`/`store_address`/`store_phone`/`store_footer` di $data
     * (kalau ada) BUKAN kolom `outlets`, jadi ditarik keluar SEBELUM
     * dipakai `Outlet::create()`/`update()` (dan tidak akan pernah masuk
     * kolom outlets biarpun bukan bagian Fillable-nya -- eksplisit di sini
     * supaya jelas disengaja, bukan diam-diam terbuang lewat mass-
     * assignment). Dipakai createOutlet()/updateOutlet() saja.
     *
     * @return array{store_name: ?string, store_address: ?string, store_phone: ?string, store_footer: ?string}
     */
    private function pullReceiptIdentity(array &$data): array
    {
        $identity = [
            'store_name' => $data['store_name'] ?? null,
            'store_address' => $data['store_address'] ?? null,
            'store_phone' => $data['store_phone'] ?? null,
            'store_footer' => $data['store_footer'] ?? null,
        ];

        unset($data['store_name'], $data['store_address'], $data['store_phone'], $data['store_footer']);

        return $identity;
    }

    /**
     * SIMETRIS dengan resolveReceiptIdentity() (baca): kalau `$outlet`
     * berstatus headquarters SETELAH disimpan (termasuk baru saja
     * dipromosikan lewat setHeadquarters() di transaksi yang sama),
     * identitas struk ditulis ke `company_settings` GLOBAL -- BUKAN kolom
     * `outlets` (yang sudah ditulis normal lewat Outlet::create()/update()
     * biasa, tidak pernah dibaca resolveReceiptIdentity() untuk
     * headquarters apa pun isinya). Cabang non-headquarters: tidak
     * melakukan apa pun di sini -- identitasnya sudah tersimpan langsung ke
     * kolom outlet lewat jalur normal, sama seperti sebelum fitur ini.
     *
     * `$outlet` HARUS state PALING BARU (`fresh()`, setelah
     * update()/setHeadquarters() selesai) -- caller yang menjamin ini,
     * bukan method ini yang query ulang, supaya tetap dalam satu
     * DB::transaction() yang sama (atomik dengan penyimpanan outlet-nya).
     */
    private function saveReceiptIdentityIfHeadquarters(Outlet $outlet, array $identity): void
    {
        if (! $outlet->is_headquarters) {
            return;
        }

        CompanySetting::current()->update([
            'store_name' => $identity['store_name'],
            'store_address' => $identity['store_address'],
            'store_phone' => $identity['store_phone'],
            'receipt_footer' => $identity['store_footer'],
        ]);
    }
}
