<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\AutonomousRuntime;

use Closure;

/**
 * Append-only JSONL heartbeat + cycle-receipt writer for the Atlas-native autonomous runtime. Pure
 * filesystem-only; no providers, no DB, no shell.
 *
 * Record shape:
 *   {schema_version, cycle_id, state, decision, safety_verdict, plan_hash, evidence_refs, ts_unix}
 *
 * Contract:
 *   - append(record) validates required fields BEFORE writing; on failure returns a blocked verdict
 *     and the file is NEVER touched (no partial write).
 *   - On success the record is appended as a single JSON line + LF (FILE_APPEND|LOCK_EX).
 *   - readRecent(limit) reads back the last N records in INSERTION ORDER.
 *   - The caller supplies an absolute local path. The ledger is idempotent on the path: calling
 *     append twice with identical content yields TWO rows (each heartbeat is its own event).
 */
final class AtlasAutonomousRuntimeHeartbeatLedger
{
    public const SCHEMA = 'atlas.autonomous_runtime.heartbeat.v1';

    public const REQUIRED_FIELDS = [
        'cycle_id',
        'state',
        'decision',
        'safety_verdict',
        'plan_hash',
        'evidence_refs',
        'ts_unix',
    ];

    public function __construct(private readonly string $path) {}

    /**
     * @param  array<string,mixed>  $record
     * @return array{appended:bool, blockers:list<string>}
     */
    public function append(array $record): array
    {
        $blockers = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $record)) {
                $blockers[] = 'missing_field:'.$field;
            }
        }
        if (! is_array($record['evidence_refs'] ?? null)) {
            $blockers[] = 'evidence_refs_not_list';
        }
        if (! is_int($record['ts_unix'] ?? null)) {
            $blockers[] = 'ts_unix_not_int';
        }

        if ($blockers !== []) {
            return ['appended' => false, 'blockers' => $blockers];
        }

        $payload = [
            'schema_version' => self::SCHEMA,
            'cycle_id' => (string) $record['cycle_id'],
            'state' => (string) $record['state'],
            'decision' => (string) $record['decision'],
            'safety_verdict' => (string) $record['safety_verdict'],
            'plan_hash' => (string) $record['plan_hash'],
            'evidence_refs' => array_values((array) $record['evidence_refs']),
            'ts_unix' => (int) $record['ts_unix'],
        ];

        $this->ensureDirectory();
        $line = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $bytes = @file_put_contents($this->path, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            return ['appended' => false, 'blockers' => ['filesystem_write_failed']];
        }

        return ['appended' => true, 'blockers' => []];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readRecent(int $limit = 50): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($limit > 0 && count($lines) > $limit) {
            $lines = array_slice($lines, -$limit);
        }
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public const STALE_THRESHOLD_SECONDS = 300;

    /**
     * Classify the latest heartbeat per phase as stale or fresh relative to a supplied now.
     *
     * @param  list<array<string,mixed>>  $records
     * @return array{stale:list<string>,fresh:list<string>}
     */
    public function classifyStalePhases(array $records, int $nowUnix, int $staleThresholdSeconds = self::STALE_THRESHOLD_SECONDS): array
    {
        $latest = [];
        foreach ($records as $record) {
            $phase = (string) ($record['state'] ?? '');
            $ts = (int) ($record['ts_unix'] ?? 0);
            if ($phase !== '' && (! isset($latest[$phase]) || $ts > $latest[$phase])) {
                $latest[$phase] = $ts;
            }
        }

        $stale = [];
        $fresh = [];
        foreach ($latest as $phase => $latestTs) {
            if (($nowUnix - $latestTs) > $staleThresholdSeconds) {
                $stale[] = $phase;
            } else {
                $fresh[] = $phase;
            }
        }

        sort($stale);
        sort($fresh);

        return ['stale' => $stale, 'fresh' => $fresh];
    }

    /**
     * Return required phases that have no record in the supplied list.
     *
     * @param  list<array<string,mixed>>  $records
     * @param  list<string>  $requiredPhases
     * @return list<string>
     */
    public function detectMissingPhases(array $records, array $requiredPhases): array
    {
        $present = [];
        foreach ($records as $record) {
            $phase = (string) ($record['state'] ?? '');
            if ($phase !== '') {
                $present[$phase] = true;
            }
        }

        $missing = array_values(array_filter($requiredPhases, static fn (string $p): bool => ! isset($present[$p])));
        sort($missing);

        return $missing;
    }

    /**
     * Count records per phase; result is ksort-stable (deterministic ordering).
     *
     * @param  list<array<string,mixed>>  $records
     * @return array{phases:array<string,int>,total:int}
     */
    public function summarize(array $records): array
    {
        $phases = [];
        foreach ($records as $record) {
            $phase = (string) ($record['state'] ?? '');
            if ($phase !== '') {
                $phases[$phase] = ($phases[$phase] ?? 0) + 1;
            }
        }
        ksort($phases);

        return ['phases' => $phases, 'total' => count($records)];
    }

    public function path(): string
    {
        return $this->path;
    }

    private function ensureDirectory(): void
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }
}
