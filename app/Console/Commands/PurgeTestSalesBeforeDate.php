<?php

namespace App\Console\Commands;

use App\Models\LedgerAdjustment;
use App\Models\Sale;
use App\Services\PostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sekali-pakai (tapi aman dijalankan lagi dengan tanggal lain kapan pun
 * dibutuhkan) -- membersihkan transaksi penjualan UJI COBA sebelum sebuah
 * tanggal cutoff (mis. sebelum go-live), TANPA menyentuh master data,
 * DAN tanpa mengganggu saldo akuntansi hari ini.
 *
 * "Tidak mengganggu akuntansi" di sini berarti: total per akun (Kas, Bank,
 * Penjualan, HPP, Persediaan, dst) yang dihitung dari SELURUH histori
 * `journal_lines` (lihat FinancialReportService::accountBalances(), tidak
 * ada snapshot per periode) tetap PERSIS SAMA sebelum & sesudah command
 * ini -- dicapai dengan memposting SATU jurnal konsolidasi (via
 * `LedgerAdjustment`, lihat model itu) berisi net kontribusi jurnal-jurnal
 * yang akan dihapus, SEBELUM baris-baris lama itu benar-benar dihapus.
 *
 * Sisi STOK sengaja TIDAK dikompensasi apa pun -- "stok saat ini" di
 * seluruh aplikasi ini selalu dibaca dari baris `stock_movements` TERBARU
 * per item (lihat InventoryService::currentStock(), Api/ItemController,
 * dll -- semua `ORDER BY id DESC LIMIT 1`, tidak pernah re-sum dari awal).
 * Menghapus baris LEBIH LAMA dari baris yang sudah ada sama sekali tidak
 * mengubah baris terbaru itu, jadi stok saat ini otomatis tidak terganggu.
 */
class PurgeTestSalesBeforeDate extends Command
{
    protected $signature = 'sales:purge-before-date {date : Tanggal cutoff (YYYY-MM-DD) -- sale SEBELUM tanggal ini yang dihapus} {--dry-run : Cuma tampilkan ringkasan, jangan ubah database apa pun} {--force : Lewati konfirmasi interaktif (mis. dipanggil non-interaktif)}';

    protected $description = 'Hapus transaksi penjualan (beserta jurnal & pergerakan stok terkait) sebelum tanggal cutoff, dengan kompensasi jurnal supaya saldo akun hari ini tidak berubah.';

    public function __construct(private readonly PostingService $posting)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $validator = Validator::make(['date' => $this->argument('date')], ['date' => ['required', 'date']]);
        if ($validator->fails()) {
            $this->error('Tanggal tidak valid: '.$validator->errors()->first('date'));

            return self::FAILURE;
        }

        $cutoff = $this->argument('date').' 00:00:00';
        $dryRun = (bool) $this->option('dry-run');

        $saleIds = Sale::query()->where('created_at', '<', $cutoff)->pluck('id');
        if ($saleIds->isEmpty()) {
            $this->info("Tidak ada sale sebelum {$this->argument('date')} -- tidak ada yang dihapus.");

            return self::SUCCESS;
        }

        $journalIds = DB::table('journals')
            ->where('source_type', Sale::class)
            ->whereIn('source_id', $saleIds)
            ->pluck('id');

        $accountTotals = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_lines.journal_id', $journalIds)
            ->selectRaw('journal_lines.account_id, accounts.code, accounts.name, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->groupBy('journal_lines.account_id', 'accounts.code', 'accounts.name')
            ->get();

        $saleLineIds = DB::table('sale_lines')->whereIn('sale_id', $saleIds)->pluck('id');
        $saleLineVariationCount = DB::table('sale_line_variations')->whereIn('sale_line_id', $saleLineIds)->count();
        $stockMovementCount = DB::table('stock_movements')
            ->where('source_type', Sale::class)
            ->whereIn('source_id', $saleIds)
            ->count();

        $this->info("Cutoff: sale SEBELUM {$this->argument('date')} 00:00:00");
        $this->line('Akan dihapus:');
        $this->line("  - sales: {$saleIds->count()}");
        $this->line("  - sale_lines: {$saleLineIds->count()}");
        $this->line("  - sale_line_variations: {$saleLineVariationCount}");
        $this->line("  - journals + journal_lines: {$journalIds->count()}");
        $this->line("  - stock_movements: {$stockMovementCount}");
        $this->line('Jurnal konsolidasi yang akan diposting (supaya saldo akun tidak berubah):');
        foreach ($accountTotals as $row) {
            $this->line("  - {$row->code} {$row->name} | debit={$row->total_debit} credit={$row->total_credit}");
        }

        if ($dryRun) {
            $this->warn('--dry-run: TIDAK ADA perubahan yang dibuat ke database.');

            return self::SUCCESS;
        }

        $confirmed = $this->option('force')
            || $this->confirm("Lanjutkan menghapus {$saleIds->count()} sale beserta data terkaitnya? Ini TIDAK BISA dibatalkan.", false);
        if (! $confirmed) {
            $this->info('Dibatalkan -- tidak ada perubahan.');

            return self::SUCCESS;
        }

        $adjustmentDate = $this->argument('date');

        DB::transaction(function () use ($saleIds, $journalIds, $saleLineIds, $accountTotals, $adjustmentDate) {
            if ($accountTotals->isNotEmpty()) {
                $adjustment = LedgerAdjustment::create([
                    'date' => $adjustmentDate,
                    'description' => "Konsolidasi saldo -- penghapusan {$saleIds->count()} transaksi uji coba sebelum {$adjustmentDate}",
                ]);

                $lines = $accountTotals->map(fn ($row) => [
                    'account' => $row->code,
                    'debit' => $row->total_debit,
                    'credit' => $row->total_credit,
                ])->all();

                $this->posting->post(
                    lines: $lines,
                    date: $adjustmentDate,
                    source: $adjustment,
                    memo: 'Konsolidasi saldo -- data uji coba sebelum go-live',
                );
            }

            DB::table('sale_line_variations')->whereIn('sale_line_id', $saleLineIds)->delete();
            DB::table('sale_lines')->whereIn('sale_id', $saleIds)->delete();
            DB::table('stock_movements')->where('source_type', Sale::class)->whereIn('source_id', $saleIds)->delete();
            DB::table('journal_lines')->whereIn('journal_id', $journalIds)->delete();
            DB::table('journals')->where('source_type', Sale::class)->whereIn('source_id', $saleIds)->delete();
            DB::table('sales')->whereIn('id', $saleIds)->delete();
        });

        $this->info('Selesai. '.$saleIds->count().' sale (beserta jurnal & pergerakan stok terkait) dihapus, 1 jurnal konsolidasi baru diposting.');

        return self::SUCCESS;
    }
}
