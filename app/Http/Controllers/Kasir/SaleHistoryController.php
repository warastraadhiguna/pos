<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\User;
use App\Services\BranchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleHistoryController extends Controller
{
    public function __construct(private readonly BranchService $branches) {}

    /**
     * Search box covers nomor transaksi (ID) + nama produk di dalamnya --
     * dua hal yang secara alami dicari lewat teks bebas (tidak ada daftar
     * "semua produk pernah terjual" yang wajar dijadikan dropdown). Kasir
     * sengaja jadi filter dropdown TERPISAH, bukan bagian dari search
     * bebas: himpunan kasir kecil & pasti (di-lookup by ID), jadi dropdown
     * lebih presisi daripada pencocokan nama sebagian yang bisa ambigu
     * antar-kasir yang namanya mirip. Metode bayar (payment_method) JUGA
     * filter dropdown terpisah sejak fitur QRIS ada -- lihat
     * `summary.by_method` untuk ringkasan "berapa QRIS vs Tunai" pada
     * rentang filter yang sedang aktif, tanpa perlu memilih salah satu
     * metode dulu.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'cashier_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'string', 'in:cash,qris'],
        ]);

        // Riwayat penjualan dicek jauh lebih sering & bervolume lebih
        // tinggi daripada PO/Beban (transaksi harian) -- default 7 hari
        // terakhir (bukan cuma hari ini) supaya langsung berguna tanpa
        // perlu diubah dulu. "Hari ini" sendirian sudah ada di Dashboard.
        $dateFrom = $filters['date_from'] ?? now()->subDays(6)->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $search = $filters['search'] ?? '';
        $cashierId = $filters['cashier_id'] ?? null;
        $paymentMethod = $filters['payment_method'] ?? null;

        $query = Sale::query()
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->when($cashierId, fn ($q) => $q->where('created_by_user_id', $cashierId))
            ->when($paymentMethod, fn ($q) => $q->where('payment_method', $paymentMethod))
            ->when($search !== '', function ($q) use ($search) {
                $saleId = ltrim(trim($search), '#');

                $q->where(function ($sq) use ($search, $saleId) {
                    if ($saleId !== '' && is_numeric($saleId)) {
                        $sq->orWhere('id', 'like', "%{$saleId}%");
                    }
                    $sq->orWhereHas('lines.product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
                });
            });

        $sales = $query
            ->orderByDesc('id')
            ->get(['id', 'date', 'occurred_at', 'grand_total', 'payment_method', 'status', 'created_by_user_id'])
            ->load('createdByUser:id,name');

        // bcmath, bukan Collection::sum() -- sum() menjumlahkan lewat
        // operator native (implicit float coercion pada string desimal),
        // melanggar disiplin uang di seluruh sistem ini.
        $total = $sales->reduce(fn (string $carry, Sale $sale) => bcadd($carry, $sale->grand_total, 4), '0');

        // "Berapa QRIS vs Tunai" pada rentang/filter yang SAMA dengan
        // daftar di atas -- dikelompokkan dari koleksi yang sudah dimuat
        // (bukan query agregat terpisah), supaya SELALU konsisten dengan
        // apa yang benar-benar tampil di tabel, terlepas dari
        // payment_method mana pun yang ditambahkan ke sistem ini nanti
        // (tidak hardcode 'cash'/'qris' di sini).
        $byMethod = $sales
            ->groupBy('payment_method')
            ->map(fn ($group, string $method) => [
                'payment_method' => $method,
                'count' => $group->count(),
                'total' => $group->reduce(fn (string $carry, Sale $sale) => bcadd($carry, $sale->grand_total, 4), '0'),
            ])
            ->sortKeys()
            ->values();

        return Inertia::render('Penjualan/Index', [
            'sales' => $sales,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
                'cashier_id' => $cashierId,
                'payment_method' => $paymentMethod,
            ],
            'cashiers' => User::whereIn('id', Sale::query()->whereNotNull('created_by_user_id')->distinct()->pluck('created_by_user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'summary' => [
                'count' => $sales->count(),
                'total' => $total,
                'by_method' => $byMethod,
            ],
        ]);
    }

    public function show(Sale $sale): Response
    {
        return Inertia::render('Penjualan/Show', [
            'sale' => $sale->load(['lines.product', 'lines.variations']),
        ]);
    }

    /**
     * Halaman struk cetak -- dipakai baik untuk "Cetak Struk" langsung
     * setelah checkout (Kasir/Index.jsx) maupun "Cetak Ulang" dari detail
     * riwayat (Penjualan/Show.jsx), supaya isinya SATU sumber yang sama.
     * Semua angka diambil APA ADANYA dari Sale yang sudah tersimpan (tidak
     * dihitung ulang) -- konsisten dengan prinsip snapshot yang sama
     * dipakai `ReceiptFormatter` di mobile: struk cetak ulang harus selalu
     * identik dengan yang pertama kali keluar, walau pengaturan toko
     * (nama, alamat, footer, saklar PPN) berubah setelahnya untuk BAGIAN
     * transaksi (subtotal/pajak/total/uang diterima/kembalian) -- catatan:
     * identitas toko sendiri TIDAK di-snapshot per transaksi (tidak ada
     * kolom untuk itu di `sales`), jadi baris itu mengikuti pengaturan
     * TERKINI, sama seperti mobile.
     *
     * Identitas per-cabang (lihat rancangan yang disetujui) -- resolve
     * dari `$sale->outlet` (cabang TEMPAT TRANSAKSI, ter-tag saat sale
     * dibuat lewat resolveCurrentOutlet(), Multi-Cabang Lapisan 3), BUKAN
     * cabang device/sesi yang sedang mencetak/reprint sekarang -- admin di
     * pusat yang reprint struk cabang lain tetap harus melihat identitas
     * cabang ASAL transaksi. `sales.outlet_id` NOT NULL sejak awal (data
     * lama di-backfill ke headquarters, Lapisan 1) jadi `$sale->outlet`
     * tidak pernah null, dan headquarters selalu resolve ke identitas
     * global -- struk lama & toko satu-lokasi identik seperti sebelum
     * fitur ini ada, otomatis (lihat BranchService::resolveReceiptIdentity()).
     */
    public function receipt(Sale $sale): Response
    {
        $identity = $this->branches->resolveReceiptIdentity($sale->outlet);

        return Inertia::render('Penjualan/Receipt', [
            'sale' => $sale->load(['lines.product', 'lines.variations', 'createdByUser']),
            'store' => [
                'name' => $identity['name'],
                'address' => $identity['address'],
                'phone' => $identity['phone'],
                'footer' => $identity['receipt_footer'],
            ],
        ]);
    }
}
