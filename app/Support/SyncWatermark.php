<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared incremental-sync mechanics for mobile API endpoints that hand out
 * an `updated_since` watermark (products, items, product-categories).
 *
 * Two deliberate design choices, both agreed with the user before coding:
 *
 * 1. The watermark returned to the client is read from the DATABASE clock,
 *    not PHP's, so it can never drift ahead of what the DB itself considers
 *    "now" — avoiding app-server/db-server clock skew if they're ever on
 *    separate hosts.
 *
 * 2. Every query filters against `updated_since - BUFFER_SECONDS`, not the
 *    raw watermark. Without this, a transaction that started before the
 *    watermark was captured but committed after it (Eloquent stamps
 *    updated_at at write-time, not commit-time) could persist a row whose
 *    updated_at falls *before* every future watermark — permanently
 *    invisible to sync. The buffer re-scans a trailing window on every
 *    call so such a row is always caught on the next sync; the client
 *    upserts by id, so occasionally re-receiving an unchanged row is a
 *    harmless no-op, not a correctness problem.
 */
class SyncWatermark
{
    private const BUFFER_SECONDS = 10;

    /**
     * The database's current time, precise enough to serve as a sync
     * watermark. Callers should return this to the client as the next
     * `updated_since` to send.
     *
     * Deliberately UTC_TIMESTAMP(), not NOW() — this MySQL server's session
     * time_zone is 'SYSTEM' (the OS's local zone, WIB/UTC+7 here), while
     * Laravel's app timezone (and every Carbon/Eloquent timestamp in this
     * app) is UTC. NOW() would return a session-local value that Carbon::
     * parse() then misreads as UTC, silently shifting the watermark by the
     * server's UTC offset. UTC_TIMESTAMP() ignores session time_zone
     * entirely, so it lines up with PHP's clock regardless of how the DB
     * server's session/global time_zone is configured.
     */
    public static function now(): Carbon
    {
        return Carbon::parse(DB::selectOne('select utc_timestamp(6) as now')->now, 'UTC');
    }

    /**
     * Apply the buffered `updated_since` lower bound to a query, if one was
     * given. Pass null/empty to skip filtering entirely (a full first sync).
     */
    public static function applyIncrementalFilter(Builder $query, ?string $updatedSince, string $column = 'updated_at'): Builder
    {
        if (! $updatedSince) {
            return $query;
        }

        $boundary = Carbon::parse($updatedSince)->subSeconds(self::BUFFER_SECONDS);

        return $query->where($column, '>=', $boundary);
    }
}
