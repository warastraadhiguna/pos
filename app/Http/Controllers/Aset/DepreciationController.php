<?php

namespace App\Http\Controllers\Aset;

use App\Http\Controllers\Controller;
use App\Models\DepreciationEntry;
use App\Services\DepreciationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DepreciationController extends Controller
{
    public function __construct(private readonly DepreciationService $depreciation) {}

    /**
     * Halaman "Proses Penyusutan": period query param (default bulan
     * berjalan) menentukan pratinjau yang ditampilkan -- TIDAK menulis
     * apa pun, murni preview lewat DepreciationService::previewForPeriod().
     * Riwayat entri yang sudah diproses ditampilkan di bawahnya.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $period = $validated['period'] ?? now()->format('Y-m');

        $preview = collect($this->depreciation->previewForPeriod($period))
            ->map(fn (array $row) => [
                'fixed_asset_id' => $row['asset']->id,
                'name' => $row['asset']->name,
                'amount' => $row['amount'],
            ]);

        $history = DepreciationEntry::with('fixedAsset')
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (DepreciationEntry $entry) => [
                'id' => $entry->id,
                'fixed_asset_name' => $entry->fixedAsset->name,
                'period' => $entry->period,
                'date' => (string) $entry->date,
                'amount' => $entry->amount,
            ]);

        return Inertia::render('Aset/Depreciation/Index', [
            'period' => $period,
            'preview' => $preview,
            'history' => $history,
        ]);
    }

    public function process(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'date' => ['required', 'date'],
        ]);

        try {
            $entries = $this->depreciation->processForPeriod(
                $validated['period'],
                $validated['date'],
                $request->user()->id,
            );
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->with('error', 'Gagal memproses penyusutan: '.$e->getMessage());
        }

        $count = count($entries);

        return Redirect::route('aset.depreciation.index', ['period' => $validated['period']])
            ->with('success', $count > 0
                ? "Penyusutan berhasil diproses untuk {$count} aset."
                : 'Tidak ada aset yang perlu diproses untuk periode ini.');
    }
}
