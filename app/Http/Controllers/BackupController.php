<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Backup/Index', [
            'backups' => $this->backups->list(),
        ]);
    }

    public function store(): RedirectResponse
    {
        try {
            $filename = $this->backups->create();
        } catch (Throwable $e) {
            report($e);

            return Redirect::route('backup.index')->with('error', 'Gagal membuat backup: '.$e->getMessage());
        }

        return Redirect::route('backup.index')->with('success', "Backup berhasil dibuat: {$filename}");
    }

    public function download(string $filename): BinaryFileResponse|RedirectResponse
    {
        try {
            $path = $this->backups->pathForDownload($filename);
        } catch (Throwable $e) {
            return Redirect::route('backup.index')->with('error', $e->getMessage());
        }

        return response()->download($path);
    }

    public function destroy(string $filename): RedirectResponse
    {
        try {
            $this->backups->delete($filename);
        } catch (Throwable $e) {
            return Redirect::route('backup.index')->with('error', $e->getMessage());
        }

        return Redirect::route('backup.index')->with('success', 'Backup berhasil dihapus.');
    }
}
