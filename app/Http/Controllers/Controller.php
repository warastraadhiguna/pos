<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

abstract class Controller
{
    /**
     * Delete a model, turning a FK constraint violation (the row is still
     * referenced elsewhere — e.g. a UOM still used by an item) into a
     * friendly flash message instead of a 500.
     */
    protected function deleteOrFail(Model $model, string $redirectRouteName, string $entityLabel): RedirectResponse
    {
        try {
            $model->delete();
        } catch (QueryException $e) {
            return Redirect::route($redirectRouteName)->with('error', "{$entityLabel} tidak bisa dihapus karena masih dipakai di data lain.");
        }

        return Redirect::route($redirectRouteName)->with('success', "{$entityLabel} berhasil dihapus.");
    }
}
