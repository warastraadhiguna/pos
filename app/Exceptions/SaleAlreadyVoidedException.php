<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `SaleService::voidSale()` when the sale's status is no longer
 * `completed` at the point of the locked re-check inside the transaction
 * (already voided by a concurrent request, or otherwise not voidable). Maps
 * to HTTP 422 in `SaleHistoryController::void()` — this is a legitimate
 * state conflict, not a server error.
 */
class SaleAlreadyVoidedException extends RuntimeException
{
}
