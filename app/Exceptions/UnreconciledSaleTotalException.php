<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when subtotal + tax_total doesn't reconcile exactly to grand_total
 * (the sum of each line's tax-inclusive price) — a sale is never persisted
 * in this state. Same discipline as UnbalancedJournalException: this
 * invariant must hold algebraically by construction (line_net + line_tax =
 * line_inclusive is guaranteed by subtraction, not independent
 * multiplication), so hitting this exception means a bug in the per-line
 * calculation, not a data issue to silently tolerate.
 */
class UnreconciledSaleTotalException extends RuntimeException
{
}
