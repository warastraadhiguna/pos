<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller-supplied change_amount doesn't match
 * cash_received − grand_total as computed by the server — the client's
 * own math disagrees with the server's, so the sale is rejected rather
 * than storing an unverified number. Same discipline as
 * UnreconciledSaleTotalException: never trust client-computed derived
 * values, always recompute and compare server-side.
 */
class UnreconciledChangeAmountException extends RuntimeException
{
}
