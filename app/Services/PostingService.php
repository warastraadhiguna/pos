<?php

namespace App\Services;

use App\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Journal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostingService
{
    private const SCALE = 4;

    /**
     * The unique index Laravel generated for journals.number (verified via
     * `SHOW INDEX FROM journals`). Used to identify a duplicate-number
     * collision precisely, as opposed to any other constraint violation.
     */
    private const NUMBER_UNIQUE_INDEX = 'journals_number_unique';

    private const MAX_NUMBER_COLLISION_RETRIES = 3;

    /** @var array<string, Account> */
    private array $accountCache = [];

    /**
     * Post a double-entry journal.
     *
     * @param  array<int, array{account: Account|string, debit?: int|float|string, credit?: int|float|string}>  $lines
     *
     * @throws UnbalancedJournalException if SUM(debit) != SUM(credit).
     * @throws InvalidArgumentException if a line carries both a debit and a credit amount.
     */
    public function post(
        array $lines,
        DateTimeInterface|string $date,
        Model $source,
        string $memo,
        ?string $number = null,
    ): Journal {
        [$normalizedLines, $totalDebit, $totalCredit] = $this->normalizeLines($lines);

        if (bccomp($totalDebit, $totalCredit, self::SCALE) !== 0) {
            throw new UnbalancedJournalException(
                "Journal is unbalanced: total debit {$totalDebit} does not equal total credit {$totalCredit}."
            );
        }

        // Auto-generated numbers get a bounded number of FRESH-transaction
        // retries if they collide with a number a concurrent post() just
        // committed (see isDuplicateJournalNumber()). Each attempt is its
        // own DB::transaction() — retrying inside the same transaction
        // would keep reading the same pre-collision state and never see
        // the competing commit. A caller-supplied $number never retries:
        // a collision there is a real error, not a race to paper over.
        $maxAttempts = $number === null ? self::MAX_NUMBER_COLLISION_RETRIES : 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($normalizedLines, $date, $source, $memo, $number) {
                    $journal = new Journal([
                        'date' => $date,
                        'number' => $number ?? $this->generateNumber($date),
                        'memo' => $memo,
                    ]);
                    $journal->source()->associate($source);
                    $journal->save();

                    foreach ($normalizedLines as $line) {
                        $journal->lines()->create([
                            'account_id' => $line['account']->id,
                            'debit' => $line['debit'],
                            'credit' => $line['credit'],
                        ]);
                    }

                    return $journal;
                });
            } catch (QueryException $e) {
                if ($attempt < $maxAttempts && $this->isDuplicateJournalNumber($e)) {
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Accept either an Account instance or an account code and resolve it to
     * an Account, so callers never have to hardcode account IDs.
     */
    public function resolveAccount(Account|string $account): Account
    {
        return $account instanceof Account ? $account : $this->getAccount($account);
    }

    /**
     * Look up an account by code, caching the result — posting a single
     * journal commonly hits the same accounts (e.g. Kas, PPN) repeatedly.
     */
    public function getAccount(string $code): Account
    {
        return $this->accountCache[$code] ??= Account::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<int, array{account: Account|string, debit?: int|float|string, credit?: int|float|string}>  $lines
     * @return array{0: array<int, array{account: Account, debit: string, credit: string}>, 1: string, 2: string}
     */
    private function normalizeLines(array $lines): array
    {
        $totalDebit = '0';
        $totalCredit = '0';
        $normalized = [];

        foreach ($lines as $line) {
            $account = $this->resolveAccount($line['account']);
            $debit = (string) ($line['debit'] ?? 0);
            $credit = (string) ($line['credit'] ?? 0);

            if (bccomp($debit, '0', self::SCALE) > 0 && bccomp($credit, '0', self::SCALE) > 0) {
                throw new InvalidArgumentException(
                    "Journal line for account [{$account->code}] cannot have both a debit and a credit amount."
                );
            }

            $totalDebit = bcadd($totalDebit, $debit, self::SCALE);
            $totalCredit = bcadd($totalCredit, $credit, self::SCALE);

            $normalized[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit];
        }

        return [$normalized, $totalDebit, $totalCredit];
    }

    /**
     * Generate the next sequential number for the month, e.g. JV-202607-0001.
     *
     * Backed by journal_number_sequences rather than "find the last journal
     * this month and add one": that approach has nothing to lockForUpdate()
     * when the month is still empty (a SELECT ... FOR UPDATE over zero rows
     * can't reliably block a concurrent transaction the way a lock on an
     * existing row can), so the very first journal of every month would be
     * a live race. The upsert below guarantees a row exists for this period
     * before we ever try to lock it — first journal of the month or the
     * hundredth, the lock always has something concrete to serialize on.
     */
    private function generateNumber(DateTimeInterface|string $date): string
    {
        $carbonDate = $date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
        $period = $carbonDate->format('Ym');

        DB::table('journal_number_sequences')->upsert(
            [['period' => $period, 'last_sequence' => 0]],
            ['period'],
            ['period'], // no-op update on conflict — this call only exists to guarantee the row is present
        );

        $sequenceRow = DB::table('journal_number_sequences')
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $nextSequence = $sequenceRow->last_sequence + 1;

        DB::table('journal_number_sequences')
            ->where('period', $period)
            ->update(['last_sequence' => $nextSequence]);

        return 'JV-'.$period.'-'.str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * True only for a duplicate-entry violation on journals.number
     * specifically — SQLSTATE 23000 alone isn't enough (NOT NULL
     * violations share it too), so this also checks the MySQL driver error
     * code (1062 = duplicate entry) and that the named unique index is the
     * one on `number`. Any other constraint violation must propagate
     * as-is, not be swallowed by the retry loop in post().
     */
    private function isDuplicateJournalNumber(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($e->errorInfo[2] ?? ''), self::NUMBER_UNIQUE_INDEX);
    }
}
