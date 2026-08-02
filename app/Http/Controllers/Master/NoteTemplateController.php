<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\NoteTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class NoteTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Master/NoteTemplates/Index', [
            'noteTemplates' => NoteTemplate::orderBy('text')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/NoteTemplates/Form', [
            'noteTemplate' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        NoteTemplate::create($this->validateData($request));

        return Redirect::route('master.note-templates.index')->with('success', 'Template catatan berhasil ditambahkan.');
    }

    public function edit(NoteTemplate $noteTemplate): Response
    {
        return Inertia::render('Master/NoteTemplates/Form', [
            'noteTemplate' => $noteTemplate,
        ]);
    }

    public function update(Request $request, NoteTemplate $noteTemplate): RedirectResponse
    {
        $noteTemplate->update($this->validateData($request));

        return Redirect::route('master.note-templates.index')->with('success', 'Template catatan berhasil diperbarui.');
    }

    /**
     * Beda dari Member/DiningTable: note_templates TIDAK direferensikan
     * oleh FK apa pun (lihat docblock model NoteTemplate), jadi delete di
     * sini tidak pernah benar-benar diblokir constraint database —
     * deleteOrFail() dipakai murni untuk pesan flash yang konsisten
     * dengan halaman master lain, bukan karena hapus di sini berisiko.
     * Menonaktifkan lewat form edit tetap jadi cara yang disarankan kalau
     * template masih ingin dipertahankan sebagai riwayat pilihan admin.
     */
    public function destroy(NoteTemplate $noteTemplate): RedirectResponse
    {
        return $this->deleteOrFail($noteTemplate, 'master.note-templates.index', 'Template catatan');
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
