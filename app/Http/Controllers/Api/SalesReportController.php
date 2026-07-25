<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReportController extends Controller
{
    public function __construct(private readonly SalesReportService $reports) {}

    /**
     * Laporan Penjualan untuk layar Laporan mobile — bungkus tipis di atas
     * SalesReportService::salesReport(), IDENTIK dengan yang dipakai
     * SalesReportController (web) untuk rentang tanggal yang sama, zero
     * logika perhitungan baru di sini. `start`/`end` default ke awal bulan
     * s/d hari ini, sama seperti default web (lihat SalesReportController
     * web), supaya membuka layar tanpa memilih tanggal dulu tetap
     * menampilkan sesuatu yang masuk akal.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        $start = $validated['start'] ?? now()->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->toDateString();

        return response()->json($this->reports->salesReport($start, $end));
    }
}
