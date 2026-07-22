<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a mobile client's claimed cashier (`client_user_id`) does not
 * match the Sanctum token that actually authenticated this request.
 *
 * The claim is NEVER trusted as an identity assertion by itself — it exists
 * purely as a cross-check against the token, which remains the only source
 * of truth for "who is making this request" (Sanctum already verified that
 * independently before this code ever runs). A mismatch means the wrong
 * cashier is currently logged in on this device relative to who actually
 * created the sale offline (device shared/handed over between cashiers,
 * offline-first) — this is a legitimate, self-resolving state, not
 * malformed input, so it maps to HTTP 409 (Api\SaleController), never 422.
 * The sale must NOT be created and must NOT be attributed to the wrong
 * cashier; the mobile client keeps it `pending` and retries once the
 * correct cashier logs back in.
 */
class CashierMismatchException extends RuntimeException
{
}
