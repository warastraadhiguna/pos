<?php

namespace App\Http\Controllers\Aset;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\Outlet;
use App\Services\CashAccountService;
use App\Services\FixedAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FixedAssetController extends Controller
{
    public function __construct(
        private readonly FixedAssetService $fixedAssets,
        private readonly CashAccountService $cashAccounts,
    ) {}

    public function index(): Response
    {
        $assets = FixedAsset::orderByDesc('id')->get()->map(fn (FixedAsset $asset) => [
            'id' => $asset->id,
            'name' => $asset->name,
            'category' => $asset->category,
            'purchase_date' => (string) $asset->purchase_date,
            'acquisition_cost' => $asset->acquisition_cost,
            'residual_value' => $asset->residual_value,
            'useful_life_months' => $asset->useful_life_months,
            'payment_method' => $asset->payment_method,
            'accumulated_depreciation' => $this->fixedAssets->accumulatedDepreciation($asset),
            'book_value' => $this->fixedAssets->bookValue($asset),
        ]);

        return Inertia::render('Aset/Index', [
            'assets' => $assets,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Aset/Create', [
            'outlets' => Outlet::orderBy('name')->get(),
            'cashAccounts' => $this->cashAccounts->selectableCashAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['required', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'min:0.01'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_years' => ['required', 'integer', 'min:1', 'max:100'],
            'payment_method' => ['required', 'in:cash,credit'],
            'cash_account_code' => ['nullable', 'string', 'max:20'],
        ]);

        $validated['useful_life_months'] = $validated['useful_life_years'] * 12;
        unset($validated['useful_life_years']);
        $validated['created_by_user_id'] = $request->user()->id;

        try {
            $this->fixedAssets->recordPurchase($validated);
        } catch (Throwable $e) {
            report($e);

            return Redirect::back()->withInput()->with('error', 'Gagal mencatat aset: '.$e->getMessage());
        }

        return Redirect::route('aset.index')->with('success', 'Aset berhasil dicatat.');
    }
}
