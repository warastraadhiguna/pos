# Device Binding

Mengunci APK mobile ke perangkat yang disetujui admin. Lihat riwayat
percakapan/rancangan untuk desain lengkap; dokumen ini murni catatan
operasional yang tidak ada di UI.

## URL admin (SENGAJA tidak ada di navigasi)

```
/pengaturan/perangkat
```

Halaman "Kelola Perangkat" — lihat daftar device (pending/approved/revoked),
setujui/cabut. Route ada dan digerbangi permission `devices.manage` seperti
halaman admin lain mana pun (bukan cuma disembunyikan dari nav — otorisasi
tetap penuh), tapi **tidak dicantumkan di `AuthenticatedLayout.jsx`**.
Admin harus mengetik URL ini langsung. Bookmark URL ini kalau perlu akses
berkala.

## Kontrol grace period

Halaman **Pengaturan** biasa (`/pengaturan`, bagian "Device Binding — Grace
Period") — admin bisa memperpanjang/mempersingkat/mematikan jendela di mana
device baru otomatis disetujui tanpa persetujuan manual. Default saat fitur
ini di-deploy: 14 hari dari tanggal migration dijalankan.

## Ringkasan alur

- Mobile app membaca Android ID (`Settings.Secure.ANDROID_ID`) sekali, kirim
  saat login (`device_id`).
- Device baru → `pending` (atau auto-`approved` kalau masih dalam grace
  period) — tidak dapat token kalau `pending`/`revoked`.
- Setelah login, app mobile cek status secara berkala (menumpang sync tick)
  ke `GET /api/v1/device/status`. Device approved yang offline < 7 hari
  tetap bisa dipakai; ≥ 7 hari tanpa cek sukses → app minta verifikasi
  ulang (butuh koneksi).
- Revoke oleh admin: instan kalau device online (token dihapus langsung),
  fallback tenggang 7 hari kalau device sedang offline.
