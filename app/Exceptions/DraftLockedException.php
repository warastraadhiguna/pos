<?php

namespace App\Exceptions;

use App\Models\Draft;
use RuntimeException;

/**
 * Thrown by DraftSyncService::hold() when a draft is currently held by
 * ANOTHER user and the lock has not timed out yet (see
 * Draft::LOCK_TIMEOUT_MINUTES) and the caller did not pass `force: true`.
 *
 * This is a legitimate, self-resolving state (the holder will eventually
 * close/finalize/time out), not malformed input — maps to HTTP 409
 * (Api\DraftController), same convention as CashierMismatchException. The
 * mobile client surfaces this as "sedang diedit oleh <nama>" with an
 * explicit "ambil alih" (force) action, never a silent retry.
 */
class DraftLockedException extends RuntimeException
{
    public function __construct(public readonly Draft $draft)
    {
        parent::__construct(
            "Draft #{$draft->id} sedang dipegang oleh {$draft->heldByUser?->name} — belum kedaluwarsa.",
        );
    }
}
