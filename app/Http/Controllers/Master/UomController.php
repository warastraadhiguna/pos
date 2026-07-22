<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Uom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class UomController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Master/Uoms/Index', [
            'uoms' => Uom::orderBy('code')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Master/Uoms/Form', [
            'uom' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:uoms,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        Uom::create($data);

        return Redirect::route('master.uoms.index')->with('success', 'UOM berhasil ditambahkan.');
    }

    public function edit(Uom $uom): Response
    {
        return Inertia::render('Master/Uoms/Form', [
            'uom' => $uom,
        ]);
    }

    public function update(Request $request, Uom $uom): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:uoms,code,'.$uom->id],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $uom->update($data);

        return Redirect::route('master.uoms.index')->with('success', 'UOM berhasil diperbarui.');
    }

    public function destroy(Uom $uom): RedirectResponse
    {
        return $this->deleteOrFail($uom, 'master.uoms.index', 'UOM');
    }
}
