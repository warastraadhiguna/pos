<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleHistoryController extends Controller
{
    /**
     * Search box covers nomor transaksi (ID) + nama produk di dalamnya --
     * dua hal yang secara alami dicari lewat teks bebas (tidak ada daftar
     * "semua produk pernah terjual" yang wajar dijadikan dropdown). Kasir
     * sengaja jadi filter dropdown TERPISAH, bukan bagian dari search
     * bebas: himpunan kasir kecil & pasti (di-lookup by ID), jadi dropdown
     * lebih presisi daripada pencocokan nama sebagian yang bisa ambigu
     * antar-kasir yang namanya mirip. Metode bayar TIDAK dijadikan filter
     * -- saat ini payment_method selalu 'cash' (lihat SaleService), jadi
     * filter itu tidak akan pernah punya nilai pembeda.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'cashier_id' => ['nullable', 'integer'],
        ]);

        // Riwayat penjualan dicek jauh lebih sering & bervolume lebih
        // tinggi daripada PO/Beban (transaksi harian) -- default 7 hari
        // terakhir (bukan cuma hari ini) supaya langsung berguna tanpa
        // perlu diubah dulu. "Hari ini" sendirian sudah ada di Dashboard.
        $dateFrom = $filters['date_from'] ?? now()->subDays(6)->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $search = $filters['search'] ?? '';
        $cashierId = $filters['cashier_id'] ?? null;

        $query = Sale::query()
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->when($cashierId, fn ($q) => $q->where('created_by_user_id', $cashierId))
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

        return Inertia::render('Penjualan/Index', [
            'sales' => $sales,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
                'cashier_id' => $cashierId,
            ],
            'cashiers' => User::whereIn('id', Sale::query()->whereNotNull('created_by_user_id')->distinct()->pluck('created_by_user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'summary' => [
                'count' => $sales->count(),
                'total' => $total,
            ],
        ]);
    }

    public function show(Sale $sale): Response
    {
        return Inertia::render('Penjualan/Show', [
            'sale' => $sale->load(['lines.product']),
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
     * identitas toko (nama/alamat/telp/footer) sendiri TIDAK di-snapshot
     * per transaksi (tidak ada kolom untuk itu di `sales`), jadi baris itu
     * mengikuti pengaturan TERKINI, sama seperti mobile (`StoreIdentity`
     * di sana juga dibaca dari `app_settings` saat cetak, bukan snapshot
     * per-transaksi).
     */
    public function receipt(Sale $sale): Response
    {
        $setting = CompanySetting::current();

        return Inertia::render('Penjualan/Receipt', [
            'sale' => $sale->load(['lines.product', 'createdByUser']),
            'store' => [
                'name' => $setting->store_name,
                'address' => $setting->store_address,
                'phone' => $setting->store_phone,
                'footer' => $setting->receipt_footer,
            ],
        ]);
    }
}
