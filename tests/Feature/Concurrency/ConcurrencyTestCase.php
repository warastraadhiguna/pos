<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Tests\TestCase;

/**
 * Shared plumbing for tests that prove lockForUpdate() actually serializes
 * two genuinely concurrent MySQL transactions.
 *
 * Deliberately does NOT use RefreshDatabase: that trait wraps an entire test
 * method in one outer transaction, which would make a "second connection"
 * meaningless (locks taken within one transaction never conflict with
 * themselves), and — worse — a subprocess on its own connection would never
 * even see fixtures created inside that still-open, not-yet-committed
 * transaction. Instead, fixtures here are committed for real and manually
 * cleaned up in tearDown(), so the shared pos_akuntansi_test database is
 * left exactly as it was found for every other test class.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Idempotent — safe to call even if the schema already exists from
        // another test class's earlier RefreshDatabase run.
        $this->artisan('migrate');
    }

    /**
     * Spawn the given artisan command as a genuinely separate OS process
     * (not pcntl_fork — unavailable on this Windows setup, and unsafe to
     * fork a process with an already-open PDO connection anyway), pinned
     * explicitly to the same test database this process is using so it
     * contends for the same rows.
     */
    protected function spawnArtisan(array $args, int $timeoutSeconds = 30): InvokedProcess
    {
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        return Process::timeout($timeoutSeconds)
            ->env($this->testDatabaseEnv())
            ->start(array_merge([$phpBinary, base_path('artisan')], $args));
    }

    /**
     * Poll a running subprocess's output until it emits $marker. Fails the
     * test (rather than hanging forever) if the subprocess exits early or
     * takes too long — a crashed helper process must never hang the suite.
     */
    protected function waitForMarker(InvokedProcess $process, string $marker, int $timeoutSeconds = 15): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (! str_contains($process->output(), $marker)) {
            if (! $process->running() || microtime(true) > $deadline) {
                $this->fail(
                    "Timed out or subprocess exited before emitting marker [{$marker}]. ".
                    'Output: '.$process->output().' | Errors: '.$process->errorOutput()
                );
            }

            usleep(50_000);
        }
    }

    /**
     * The exact DB connection this test process is using (resolved from
     * config, not hardcoded), passed explicitly to the subprocess rather
     * than relied upon via environment inheritance.
     */
    private function testDatabaseEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        ];
    }
}
