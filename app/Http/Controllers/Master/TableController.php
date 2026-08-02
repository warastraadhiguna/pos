<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class TableController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Master/Tables/Index', [
            'tables' => DiningTable::orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/Tables/Form', [
            'table' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        DiningTable::create($this->validateData($request));

        return Redirect::route('master.tables.index')->with('success', 'Meja berhasil ditambahkan.');
    }

    public function edit(DiningTable $table): Response
    {
        return Inertia::render('Master/Tables/Form', [
            'table' => $table,
        ]);
    }

    public function update(Request $request, DiningTable $table): RedirectResponse
    {
        $table->update($this->validateData($request));

        return Redirect::route('master.tables.index')->with('success', 'Meja berhasil diperbarui.');
    }

    /**
     * Blocked by the DB (restrictOnDelete on sales.table_id) if this table
     * already has sales -- the admin should nonaktifkan (uncheck "Aktif" on
     * the edit form) instead, same pattern as Member/Product/Item.
     */
    public function destroy(DiningTable $table): RedirectResponse
    {
        return $this->deleteOrFail($table, 'master.tables.index', 'Meja');
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'area' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
