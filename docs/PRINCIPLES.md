# Prinsip Desain — WAJIB Dipatuhi

Dokumen ini adalah kontrak disiplin untuk seluruh pengembangan proyek ini. Setiap tugas baru — fitur, service, migration, refactor — harus konsisten dengan prinsip di bawah. Kalau sebuah permintaan tampak bertentangan dengan salah satu prinsip ini, itu sinyal untuk berhenti dan klarifikasi ke user, bukan untuk diam-diam menyimpang.

## Prinsip inti

1. **Semua uang dan kuantitas: `decimal(18,4)` + bcmath, tidak pernah float.**
   Semua kolom uang/qty di database bertipe `decimal(18,4)`. Semua perhitungan di PHP pakai `bcadd`/`bcsub`/`bcmul`/`bcdiv`/`bccomp` dengan scale 4 — bukan operator aritmatika native (`+`, `-`, `*`, `/`) dan bukan `(float)`. Float kehilangan presisi dan tidak boleh dipakai untuk uang.

2. **Semua business logic ada di Service layer, bukan di controller.**
   Controller (kalau/ketika dibuat) hanya menerima request, memanggil service, dan mengembalikan response. Tidak ada perhitungan HPP, validasi saldo jurnal, atau logika stok yang hidup di controller.

3. **Semua jurnal WAJIB lewat `PostingService`, dan SUM(debit) harus SAMA DENGAN SUM(credit).**
   Tidak ada kode lain yang boleh menulis langsung ke `journals`/`journal_lines`. Validasi balance terjadi sebelum commit; kalau tidak seimbang, `PostingService` melempar `UnbalancedJournalException` dan tidak menyimpan apa pun.

4. **Stok hanya lewat `stock_movements` — tidak ada kolom qty tunggal di manapun.**
   Tidak ada `items.qty` atau sejenisnya. Stok saat ini selalu dihitung dari `running_qty` pada movement terakhir (lewat `InventoryService`). Costing pakai Moving Average, disimpan sebagai `running_average_cost` per movement.

5. **Semua operasi multi-langkah dalam satu `DB::transaction()`, dengan rollback yang teruji.**
   Kalau sebuah alur menyentuh lebih dari satu tabel (mis. sale + sale_lines + stock_movements + journal), semuanya harus dalam satu transaction. Setiap service baru yang punya sifat ini wajib disertai test yang secara eksplisit membuktikan rollback bekerja (bukan cuma jalur sukses).

6. **Kode akun diambil dari Chart of Accounts lewat resolusi by kode — tidak pernah hardcode ID.**
   Referensi akun pakai kode (mis. `1-1200`) yang diresolve lewat `PostingService::resolveAccount()`/`getAccount()`, konsisten dengan kode yang di-seed `FoundationSeeder`. Tidak ada `Account::find(3)` atau angka ID magic di service manapun.

7. **Tabel transaksi punya `outlet_id`/`warehouse_id`.**
   Semua tabel transaksi (`sales`, `purchase_orders`, `goods_receipts`, `stock_movements`, `stock_opnames`, dst.) menyimpan `outlet_id` dan/atau `warehouse_id` sejak awal, meski untuk sekarang selalu bernilai 1 — supaya multi-outlet bisa ditambahkan nanti tanpa migrasi struktur besar.

8. **Setiap fitur baru WAJIB disertai test yang jalan di MySQL — bukan hanya SQLite.**
   `phpunit.xml` sudah diarahkan ke database test terpisah (`pos_akuntansi_test`) di MySQL, terpisah dari database dev (`pos_akuntansi`). SQLite tidak merepresentasikan perilaku `lockForUpdate()` maupun presisi `DECIMAL` yang sebenarnya, jadi tidak cukup untuk memvalidasi logika stok/akuntansi.

## ATURAN PENTING — gerbang konfirmasi

**Sebelum mengerjakan tugas yang menyentuh skema database atau logika akuntansi/stok**, berhenti dulu dan tunjukkan ke user:

- Keputusan desain utama yang akan diambil.
- Risiko dan asumsi tersembunyi (termasuk hal yang mahal diubah nanti kalau keliru).

Baru eksekusi setelah user mengonfirmasi. Jangan diam-diam berasumsi pada hal yang mahal diubah nanti — kalau ada keraguan tentang keputusan desain yang berdampak jangka panjang, tanya dulu, jangan tebak.
