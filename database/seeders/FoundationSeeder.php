<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Outlet;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\UomConversion;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    /**
     * Seed the foundational master data: outlet, warehouse, UOM, chart of
     * accounts, tax rate, and the company_settings singleton.
     *
     * `ppn_active` defaults to false — toko belum PKP saat go-live
     * pertama. Tarif PPN 11% tetap diseed (bukan dihapus) supaya begitu
     * toko jadi PKP, admin tinggal nyalakan saklarnya lewat UI
     * (/pengaturan) tanpa perlu seed ulang tarif.
     */
    public function run(): void
    {
        $outlet = Outlet::create(['name' => 'Outlet Pusat']);

        Warehouse::create([
            'outlet_id' => $outlet->id,
            'name' => 'Gudang Utama',
        ]);

        $uoms = collect([
            ['code' => 'PCS', 'name' => 'Pieces'],
            ['code' => 'GR', 'name' => 'Gram'],
            ['code' => 'KG', 'name' => 'Kilogram'],
            ['code' => 'SAK', 'name' => 'Karung'],
            ['code' => 'ML', 'name' => 'Mililiter'],
            ['code' => 'LTR', 'name' => 'Liter'],
        ])->mapWithKeys(fn (array $uom) => [$uom['code'] => Uom::create($uom)]);

        UomConversion::create([
            'from_uom_id' => $uoms['KG']->id,
            'to_uom_id' => $uoms['GR']->id,
            'factor' => 1000,
        ]);

        UomConversion::create([
            'from_uom_id' => $uoms['SAK']->id,
            'to_uom_id' => $uoms['GR']->id,
            'factor' => 25000,
        ]);

        UomConversion::create([
            'from_uom_id' => $uoms['LTR']->id,
            'to_uom_id' => $uoms['ML']->id,
            'factor' => 1000,
        ]);

        // Grup "Kas & Bank" (kode "1-1") dibuat oleh migration
        // 2026_07_22_100000 -- migration selalu jalan sebelum seeder ini
        // (baik `migrate --seed` maupun `migrate:fresh --seed`), jadi baris
        // ini pasti sudah ada di titik ini.
        $kasBankGroupId = Account::where('code', '1-1')->value('id');

        $accounts = collect([
            ['code' => '1-1000', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $kasBankGroupId],
            ['code' => '1-1200', 'name' => 'Persediaan', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1-1300', 'name' => 'PPN Masukan', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2-1000', 'name' => 'Hutang Usaha', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2-1100', 'name' => 'PPN Keluaran', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '4-1000', 'name' => 'Penjualan', 'type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '5-1000', 'name' => 'HPP', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5-2000', 'name' => 'Selisih Persediaan', 'type' => 'expense', 'normal_balance' => 'debit'],
        ])->mapWithKeys(fn (array $account) => [$account['code'] => Account::create($account)]);

        TaxRate::create([
            'name' => 'PPN 11%',
            'rate' => 0.11,
            'output_account_id' => $accounts['2-1100']->id,
            'input_account_id' => $accounts['1-1300']->id,
        ]);

        CompanySetting::create(['ppn_active' => false]);
    }
}
