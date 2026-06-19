<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * S3 — the read+write seam over `atlas_loop_failure_handles`: the durable BUG-HANDLE store
 * that supplies the already-wired bug-fix reproduction lane with REAL, runnable failure work.
 *
 * {@see handlesByPath()} is the read side the discovery STAMP consumes — given the scanned
 * candidate paths it returns the matching handle rows keyed by path, so the discovery service
 * can write failure_test_path/failure_command/failing_assertion/failure_message onto a target's
 * signals (which then persist via the repository upsert and trigger tryBugReproduction).
 *
 * {@see upsertHandle()} is the write side the PRODUCER (SuiteRedTestHandleHarvester) uses to
 * persist a triaged real_failure as a runnable handle, deduped on a deterministic path|test key.
 *
 * FAIL-OPEN by contract: a missing table (the migration not yet run) yields an empty read and a
 * no-op write — the discovery stamp never sees a handle and the live path stays byte-identical.
 */
final class AtlasLoopFailureHandleSource
{
    public const TABLE = 'atlas_loop_failure_handles';

    /**
     * Deterministic dedup key for a (target path, reproducing test) pair — stable across
     * harvest runs so re-seeing the same red bumps recurrence_count instead of duplicating.
     */
    public function dedupKey(string $path, string $testPath): string
    {
        return hash('sha256', ltrim($path, '/').'|'.ltrim($testPath, '/'));
    }

    /**
     * Read the handle rows for the given candidate paths, keyed by repo-relative path. When more
     * than one handle exists for a path the most-recurrent (then most-recent) wins — the strongest
     * deterministic signal anchors the stamp. Fail-open: missing table / any error => [].
     *
     * @param  list<string>  $paths
     * @return array<string, array{path:string, failure_test_path:string, failure_command:?string, failing_assertion:?string, failure_message:?string, recurrence_count:int}>
     */
    public function handlesByPath(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(array_map(
            static fn ($p): string => ltrim((string) $p, '/'),
            $paths,
        ), static fn (string $p): bool => $p !== '')));
        if ($paths === [] || ! DatabaseTableAvailability::has(self::TABLE)) {
            return [];
        }

        try {
            $rows = DB::table(self::TABLE)
                ->whereIn('path', $paths)
                ->orderBy('recurrence_count', 'desc')
                ->orderBy('last_seen_at', 'desc')
                ->get();

            $byPath = [];
            foreach ($rows as $row) {
                $path = ltrim((string) $row->path, '/');
                if ($path === '' || isset($byPath[$path])) {
                    continue; // first row per path wins (already ordered strongest-first)
                }
                $testPath = (string) $row->failure_test_path;
                $command = $row->failure_command !== null ? (string) $row->failure_command : null;
                // A handle the lane can actually run MUST carry a test path or an explicit command.
                if (trim($testPath) === '' && ($command === null || trim($command) === '')) {
                    continue;
                }
                $byPath[$path] = [
                    'path' => $path,
                    'failure_test_path' => $testPath,
                    'failure_command' => $command,
                    'failing_assertion' => $row->failing_assertion !== null ? (string) $row->failing_assertion : null,
                    'failure_message' => $row->failure_message !== null ? (string) $row->failure_message : null,
                    'recurrence_count' => (int) $row->recurrence_count,
                ];
            }

            return $byPath;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Persist (or bump) a runnable bug handle. Deduped on dedupKey(path, test_path): a re-seen
     * red increments recurrence_count + refreshes last_seen_at rather than duplicating. Returns
     * true on a successful write/bump, false on a fail-open no-op (missing table / error / no
     * runnable handle). The PRODUCER is responsible for having already triaged the red as real.
     */
    public function upsertHandle(
        string $path,
        string $failureTestPath,
        ?string $failureCommand = null,
        ?string $failingAssertion = null,
        ?string $failureMessage = null,
    ): bool {
        $path = ltrim(trim($path), '/');
        $failureTestPath = ltrim(trim($failureTestPath), '/');
        $failureCommand = $failureCommand !== null && trim($failureCommand) !== '' ? trim($failureCommand) : null;
        // Without a runnable handle (a test path OR an explicit command) the lane cannot fix
        // against it — refuse to store an un-runnable row.
        if ($path === '' || ($failureTestPath === '' && $failureCommand === null)) {
            return false;
        }
        if (! DatabaseTableAvailability::has(self::TABLE)) {
            return false;
        }

        try {
            $key = $this->dedupKey($path, $failureTestPath);
            $now = Carbon::now();
            $existing = DB::table(self::TABLE)->where('dedup_key', $key)->first();
            if ($existing !== null) {
                DB::table(self::TABLE)->where('dedup_key', $key)->update([
                    'recurrence_count' => (int) $existing->recurrence_count + 1,
                    'failure_command' => $failureCommand ?? ($existing->failure_command ?? null),
                    'failing_assertion' => $failingAssertion ?? ($existing->failing_assertion ?? null),
                    'failure_message' => $failureMessage ?? ($existing->failure_message ?? null),
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);

                return true;
            }

            DB::table(self::TABLE)->insert([
                'id' => (string) Str::uuid(),
                'dedup_key' => $key,
                'path' => $path,
                'failure_test_path' => $failureTestPath,
                'failure_command' => $failureCommand,
                'failing_assertion' => $failingAssertion,
                'failure_message' => $failureMessage,
                'recurrence_count' => 1,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
