<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by SaleService::createSale() when a sale carries a nonzero
 * discount_value but CompanySetting::current()->discount_enabled is false
 * -- jaring pengaman kedua, mengulang pemeriksaan yang seharusnya sudah
 * mencegah UI menampilkan field Diskon sama sekali (lihat
 * SettingController::updateDiscountEnabled()). Same discipline as
 * InvalidQrisAccountException: reflects a client that's out of sync with
 * the current setting (mis. saklar baru dimatikan setelah dialog Bayar
 * sempat terbuka), expected to surface as a normal 422 to the caller, not
 * a bug report.
 */
class DiscountDisabledException extends RuntimeException
{
}
