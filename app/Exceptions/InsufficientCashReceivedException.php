<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller supplies cash_received (mobile POS checkout) that's
 * less than the sale's grand_total — the customer literally didn't hand
 * over enough cash to cover the sale. Unlike UnreconciledSaleTotalException
 * (an internal-calculation invariant that should never fail), this reflects
 * bad/incomplete client input and is expected to surface as a normal 422 to
 * the caller, not a bug report.
 */
class InsufficientCashReceivedException extends RuntimeException
{
}
