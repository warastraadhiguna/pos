<?php

namespace App\Services;

use App\Exceptions\DraftLockedException;
use App\Models\Draft;
use App\Models\DraftLine;
use App\Models\Outlet;
use App\Models\User;
use App\Support\SyncWatermark;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Draft sync lintas-device (langkah 3/3 fitur Draft) -- offline-first
 * (pola `local_uuid` sama seperti Sale), soft-lock satu-pemegang, dan
 * resolusi konflik PER-ITEM (bukan per-draft) supaya edit ke baris A oleh
 * satu device tidak pernah menimpa edit ke baris B oleh device lain.
 *
 * TIGA hal yang SENGAJA beda dari pola SaleService::createSale():
 * 1. Sale immutable sekali dibuat (replay = kembalikan yang sudah ada
 *    apa adanya). Draft mutable berkali-kali -- push() adalah UPSERT
 *    sungguhan, bukan create-atau-replay.
 * 2. Konflik dulu diselesaikan per-BARIS (`mergeLine()`), bukan per-draft
 *    -- draft sendiri butuh identitas per-baris (`draft_lines.local_uuid`)
 *    yang tidak dibutuhkan Sale sama sekali.
 * 3. `is_printed` TIDAK ikut aturan "konten lebih baru menang" seperti
 *    field lain -- dia SELALU di-OR (union), lihat docblock mergeLine()
 *    untuk skenario konkret kenapa ini wajib supaya tidak ada tiket dapur
 *    yang "hilang" dari sudut pandang server gara-gara push device lain
 *    yang cuma belum tahu soal cetakan itu.
 */
class DraftSyncService
{
    /**
     * UPSERT draft + baris-barisnya oleh [$user] (device yang mengautentikasi
     * request ini). Draft yang sudah `finalized`/`cancelled` TIDAK PERNAH
     * dihidupkan lagi oleh push manapun -- device offline yang telat push
     * edit ke draft yang sudah selesai duluan di device lain cukup diabaikan
     * isinya (draft itu sudah tidak relevan, lihat status di respons).
     */
    public function push(array $data, User $user): Draft
    {
        return DB::transaction(function () use ($data, $user) {
            $draft = Draft::where('local_uuid', $data['local_uuid'])->lockForUpdate()->first();

            if (! $draft) {
                $outlet = Outlet::firstOrFail();
                $draft = Draft::create([
                    'outlet_id' => $outlet->id,
                    'local_uuid' => $data['local_uuid'],
                    'status' => 'open',
                    'created_by_user_id' => $user->id,
                    'member_id' => $data['member_id'] ?? null,
                    'member_name_snapshot' => $data['member_name'] ?? null,
                    'table_id' => $data['table_id'] ?? null,
                    'table_name_snapshot' => $data['table_name'] ?? null,
                    'note' => $data['note'] ?? null,
                ]);
            } elseif ($draft->status === 'open') {
                // Header: last-write-wins polos by kedatangan -- risiko
                // terendah di seluruh rancangan ini (tidak ada efek fisik
                // seperti is_printed), jadi sengaja TIDAK diberi mekanisme
                // per-field seperti baris. Kalau draft SUDAH finalized/
                // cancelled, header TIDAK disentuh sama sekali (lihat
                // docblock method ini).
                $draft->update([
                    'member_id' => $data['member_id'] ?? null,
                    'member_name_snapshot' => $data['member_name'] ?? null,
                    'table_id' => $data['table_id'] ?? null,
                    'table_name_snapshot' => $data['table_name'] ?? null,
                    'note' => $data['note'] ?? null,
                ]);
            }

            if ($draft->status === 'open') {
                foreach ($data['lines'] as $lineData) {
                    $this->mergeLine($draft, $lineData);
                }
            }

            return $draft->fresh(['lines.variations']);
        });
    }

    /**
     * Merge SATU baris masuk terhadap baris server (dicocokkan lewat
     * `local_uuid`) -- lihat docblock kolom `content_updated_at` di
     * migrasi `draft_lines` untuk definisi "konten".
     *
     * `is_printed` SENGAJA dipisah total dari menang-kalahnya konten:
     * dia SELALU `server.is_printed OR incoming.is_printed`, di SEMUA
     * cabang (baru/sama/lebih lama) -- kalau device manapun PERNAH
     * bilang "saya sudah cetak ini", fakta itu tidak boleh hilang cuma
     * karena device LAIN mengirim snapshot yang lebih baru untuk qty/
     * catatan/variasi (atau bahkan snapshot yang lebih lama). Contoh
     * konkret yang membuktikan kenapa aturan ini wajib:
     *
     *   Device A & B mulai dari baris yang sama (content_updated_at=T0,
     *   is_printed=false). B mencetak (is_printed=true, TIDAK mengubah
     *   content_updated_at -- mencetak bukan perubahan konten) lalu push
     *   duluan -- server: content_updated_at=T0, is_printed=true.
     *   A (tidak tahu B mencetak) menambah qty (content_updated_at=T1>T0,
     *   is_printed tetap false di sisi A -- A tidak pernah menyentuh
     *   status cetak) lalu push belakangan. incoming.content_updated_at
     *   (T1) > server (T0) -- konten A menang (qty A yang benar). Kalau
     *   is_printed ikut "menang penuh" bersama konten, cetakan B akan
     *   HILANG dari server (server.is_printed jadi false lagi) padahal
     *   FISIKNYA sudah tercetak ke dapur -- risiko dobel-cetak persis
     *   yang diminta dihindari. Dengan is_printed SELALU di-OR (lepas
     *   dari cabang mana pun), hasilnya benar: qty A menang, is_printed
     *   tetap true.
     */
    private function mergeLine(Draft $draft, array $lineData): void
    {
        $existing = DraftLine::where('draft_id', $draft->id)
            ->where('local_uuid', $lineData['local_uuid'])
            ->lockForUpdate()
            ->first();

        $incomingContentUpdatedAt = Carbon::parse($lineData['content_updated_at']);
        $incomingIsPrinted = (bool) ($lineData['is_printed'] ?? false);

        if (! $existing) {
            $line = DraftLine::create([
                'draft_id' => $draft->id,
                'local_uuid' => $lineData['local_uuid'],
                'product_id' => $lineData['product_id'],
                'product_name_snapshot' => $lineData['product_name_snapshot'],
                'qty' => $lineData['qty'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $lineData['tax_rate'] ?? 0,
                'line_total' => $lineData['line_total'],
                'note' => $lineData['note'] ?? null,
                'is_printed' => $incomingIsPrinted,
                'is_deleted' => (bool) ($lineData['is_deleted'] ?? false),
                'content_updated_at' => $incomingContentUpdatedAt,
            ]);
            $this->syncLineVariations($line, $lineData['variations'] ?? []);

            return;
        }

        if ($incomingContentUpdatedAt->greaterThan($existing->content_updated_at)) {
            // Konten lebih baru -- device ini punya pengetahuan paling
            // mutakhir soal baris ini (termasuk apakah edit lokalnya tadi
            // sengaja mereset status cetak, lihat CartController langkah
            // 2), adopsi konten PENUH.
            $existing->update([
                'product_id' => $lineData['product_id'],
                'product_name_snapshot' => $lineData['product_name_snapshot'],
                'qty' => $lineData['qty'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $lineData['tax_rate'] ?? 0,
                'line_total' => $lineData['line_total'],
                'note' => $lineData['note'] ?? null,
                'is_deleted' => (bool) ($lineData['is_deleted'] ?? false),
                'content_updated_at' => $incomingContentUpdatedAt,
            ]);
            $this->syncLineVariations($existing, $lineData['variations'] ?? []);
        }
        // else: konten SAMA-tua atau LEBIH TUA dari server -- diabaikan
        // (server sudah punya versi konten yang sama baik atau lebih
        // baik). Tie sungguhan (dua device edit dari basis yang sama)
        // sengaja TIDAK diberi tie-break lain -- "diabaikan" pada tie
        // berarti versi yang SUDAH ada di server yang menang, konsisten
        // & dapat dijelaskan (siapa pun yang pushnya diproses server
        // LEBIH DULU untuk kombinasi timestamp yang sama persis, menang).

        if ($incomingIsPrinted && ! $existing->is_printed) {
            $existing->update(['is_printed' => true]);
        }
    }

    private function syncLineVariations(DraftLine $line, array $variations): void
    {
        $line->variations()->delete();
        foreach ($variations as $variation) {
            $line->variations()->create([
                'variation_id' => $variation['variation_id'],
                'name_snapshot' => $variation['name'] ?? '',
                'price_snapshot' => $variation['price'] ?? 0,
            ]);
        }
    }

    /**
     * Draft `status='open'` (full pull) ATAU draft apa pun yang berubah
     * sejak [$updatedSince] TERMASUK yang baru saja `finalized`/
     * `cancelled` (incremental) -- device yang sudah cache draft itu
     * perlu tahu untuk menghapusnya dari daftar lokal, bukan cuma device
     * yang belum pernah melihatnya. Lihat docblock kolom `status` di
     * migrasi `drafts` untuk kenapa ENUM eksplisit (bukan soft-delete)
     * dipakai -- supaya baris ini TIDAK tersembunyi dari query di atas.
     */
    public function pull(?string $updatedSince): Collection
    {
        $query = Draft::with(['lines' => fn ($q) => $q->orderBy('id'), 'lines.variations', 'heldByUser']);

        if ($updatedSince === null) {
            $query->where('status', 'open');
        } else {
            SyncWatermark::applyIncrementalFilter($query, $updatedSince);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Klaim soft-lock -- UPDATE bersyarat ATOMIK (bukan baca-lalu-tulis)
     * supaya aman di bawah dua request race sungguhan: WHERE-nya sendiri
     * yang jadi penjaga (InnoDB mengunci baris saat mengevaluasi WHERE
     * sebuah UPDATE, request kedua menunggu request pertama commit lalu
     * mengevaluasi ulang terhadap baris yang SUDAH berubah) -- bukan
     * "cek dulu di PHP baru update", yang punya celah TOCTOU di bawah
     * concurrency sungguhan. Lihat
     * DraftSyncConcurrencyTest::test_two_concurrent_holds_on_the_same_draft_only_one_wins
     * untuk bukti lewat proses OS terpisah sungguhan.
     *
     * [$force] = true SELALU berhasil (kasir memilih "Ambil Alih" di
     * dialog peringatan) -- lock BUKAN penjamin data (penjamin datanya
     * `mergeLine()` di atas), murni pagar UX supaya dua orang tidak
     * diam-diam mengedit draft yang sama tanpa saling tahu.
     */
    public function hold(Draft $draft, User $user, ?string $deviceLabel, bool $force = false): Draft
    {
        $query = DB::table('drafts')->where('id', $draft->id);

        if (! $force) {
            $timeoutBoundary = now()->subMinutes(Draft::LOCK_TIMEOUT_MINUTES);
            $query->where(function ($q) use ($user, $timeoutBoundary) {
                $q->whereNull('held_by_user_id')
                    ->orWhere('held_by_user_id', $user->id)
                    ->orWhere('held_at', '<', $timeoutBoundary);
            });
        }

        $affected = $query->update([
            'held_by_user_id' => $user->id,
            'held_by_device_label' => $deviceLabel,
            'held_at' => now(),
            'updated_at' => now(),
        ]);

        if ($affected === 0) {
            throw new DraftLockedException($draft->fresh());
        }

        return $draft->fresh();
    }

    /**
     * Lepas lock -- no-op (bukan error) kalau [$user] BUKAN pemegangnya
     * sekarang (mis. sudah keburu direbut device lain lewat timeout) --
     * pola sama `DraftRepository.delete()` yang no-op kalau draft sudah
     * tidak ada, tidak ada yang perlu "dibatalkan" dari sudut pandang
     * pemanggil.
     */
    public function release(Draft $draft, User $user): void
    {
        DB::table('drafts')
            ->where('id', $draft->id)
            ->where('held_by_user_id', $user->id)
            ->update([
                'held_by_user_id' => null,
                'held_by_device_label' => null,
                'held_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Kasir menghapus draft ini secara lokal (lihat `DraftRepository.delete()`
     * di mobile) -- ditandai `cancelled`, BUKAN dihapus fisik, pola PERSIS
     * `finalizeByLocalUuid()`: status eksplisit inilah yang membuat pull
     * berikutnya (baik dari device yang menghapus MAUPUN device lain yang
     * sempat melihat draft ini) tahu untuk berhenti menampilkannya, alih-
     * alih diam-diam "resurrect" lewat snapshot server yang masih `open`.
     * TIDAK memeriksa status lock -- konsisten dengan `finalizeByLocalUuid()`
     * (lock murni pagar UX, bukan penjamin data, lihat docblock `hold()`).
     * No-op diam-diam kalau [$draft] belum pernah ter-push (dipanggil
     * pemanggil HANYA saat `serverId` terisi, lihat `DraftSyncRepository`
     * mobile) -- draft murni lokal tidak punya apa pun untuk dibatalkan
     * di server.
     */
    public function cancel(Draft $draft): void
    {
        DB::table('drafts')->where('id', $draft->id)->update([
            'status' => 'cancelled',
            'held_by_user_id' => null,
            'held_by_device_label' => null,
            'held_at' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Dipanggil SaleService::createSale() DALAM transaksi yang sama saat
     * sale membawa `draft_local_uuid` -- lihat docblocknya. Lock ikut
     * dilepas (draft yang sudah final tidak "dipegang" siapa pun lagi).
     * No-op diam-diam kalau uuid tidak dikenali (draft lokal lama yang
     * belum pernah sync sama sekali, atau sudah dihapus manual) --
     * finalisasi sale TIDAK BOLEH gagal gara-gara draft-nya sendiri
     * entah kenapa tidak ada di server.
     */
    public function finalizeByLocalUuid(?string $draftLocalUuid): void
    {
        if ($draftLocalUuid === null) {
            return;
        }

        Draft::where('local_uuid', $draftLocalUuid)->update([
            'status' => 'finalized',
            'held_by_user_id' => null,
            'held_by_device_label' => null,
            'held_at' => null,
        ]);
    }
}
