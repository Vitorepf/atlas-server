<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\JsonFileStore;
use DateTimeImmutable;
use RuntimeException;

/**
 * Rivals Forge Run Log Stream v1.
 *
 * Append-only JSONL stream of structured events for a real Rivals run.
 * Every stage of the orchestrator publishes an event here so the operator
 * has incremental visibility (instead of a 40-minute silent subprocess).
 * The stall detector reads {@see lastEventAt()} to decide when no
 * heartbeat has arrived and the run should be aborted with
 * `stalled_runner_no_heartbeat`.
 *
 * Storage layout (anchored on Laravel storage):
 *   storage/app/rivals-forge-runs/
 *     <runId>/
 *       events.jsonl  -- one event per line
 *       run.log       -- human-readable tail (mirror of events)
 *       intent.json   -- the orchestrator intent captured at start
 *
 * Schema: atlas.programming.rivals_forge_run_log_stream.v1
 */
class RivalsForgeRunLogStreamService
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_forge_run_log_stream.v1';

    /** @var list<string> Canonical event kinds emitted by the orchestrator. */
    public const CANONICAL_EVENT_KINDS = [
        'run_started',
        'preflight',
        'dry_run',
        'provider_start',
        'provider_progress',
        'provider_done',
        'tests_start',
        'tests_done',
        'quality_start',
        'quality_done',
        'after_clean_check',
        'evidence_pack',
        'heartbeat',
        'blocked',
        'stalled',
        'final_report',
    ];

    /** How many run directories to keep on disk before rotating. */
    public const RETENTION_RUNS = 20;

    public function rootDirectory(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'rivals-forge-runs');
    }

    public function runDirectory(string $runId): string
    {
        return $this->rootDirectory().DIRECTORY_SEPARATOR.$this->sanitizeRunId($runId);
    }

    /**
     * Initialize a new run directory and write the `run_started` event.
     *
     * @param  array<string,mixed>  $intent
     */
    public function start(string $runId, array $intent): string
    {
        $runId = $this->sanitizeRunId($runId);
        $dir = $this->runDirectory($runId);
        @mkdir($dir, 0o755, true);

        $intentPayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'intent' => $intent,
            'started_at' => now()->toJSON(),
        ];
        $intentJsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $intentBlob = json_encode($intentPayload, $intentJsonFlags) ?: '{}';
        JsonFileStore::write($dir.DIRECTORY_SEPARATOR.'intent.json', $intentPayload, $intentJsonFlags);

        $this->event($runId, 'run_started', [
            'run_id' => $runId,
            'intent_hash' => hash('sha256', $intentBlob),
        ]);

        $this->rotate();

        return $dir;
    }

    /**
     * Append a single JSONL event line to the stream + human log mirror.
     *
     * @param  array<string,mixed>  $payload
     */
    public function event(string $runId, string $kind, array $payload = []): void
    {
        $runId = $this->sanitizeRunId($runId);
        $dir = $this->runDirectory($runId);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        $jsonl = $dir.DIRECTORY_SEPARATOR.'events.jsonl';
        $humanLog = $dir.DIRECTORY_SEPARATOR.'run.log';

        $ts = now();
        $monotonic = $this->monotonicMsSinceStart($dir);

        $record = [
            'ts' => $ts->toJSON(),
            'monotonic_ms_since_start' => $monotonic,
            'kind' => $kind,
            'payload' => $payload,
        ];
        AppendOnlyJsonlStore::append($jsonl, $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $humanLine = sprintf('[%s][+%07dms] %s %s%s',
            $ts->toJSON(),
            $monotonic,
            str_pad($kind, 20),
            $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            PHP_EOL,
        );
        @file_put_contents($humanLog, $humanLine, FILE_APPEND);
    }

    public function heartbeat(string $runId, ?string $note = null): void
    {
        $this->event($runId, 'heartbeat', $note === null ? [] : ['note' => $note]);
    }

    /**
     * Read events from a run. `$sinceLine` lets callers tail incrementally.
     *
     * @return list<array<string,mixed>>
     */
    public function tail(string $runId, int $sinceLine = 0): array
    {
        $runId = $this->sanitizeRunId($runId);
        $path = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'events.jsonl';
        return array_slice(AppendOnlyJsonlStore::read($path), max(0, $sinceLine));
    }

    public function lastEventAt(string $runId): ?DateTimeImmutable
    {
        $events = $this->tail($runId, 0);
        if ($events === []) {
            return null;
        }
        $last = $events[array_key_last($events)];
        $ts = is_string($last['ts'] ?? null) ? $last['ts'] : null;
        if ($ts === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($ts);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function listRuns(): array
    {
        $root = $this->rootDirectory();
        if (! is_dir($root)) {
            return [];
        }
        $dirs = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        $names = array_map(static fn (string $p): string => basename($p), $dirs);
        sort($names, SORT_STRING);

        return $names;
    }

    public function latestRunId(): ?string
    {
        $runs = $this->listRuns();
        if ($runs === []) {
            return null;
        }
        usort($runs, function (string $a, string $b): int {
            $rootA = $this->runDirectory($a);
            $rootB = $this->runDirectory($b);

            return (filemtime($rootB) ?: 0) <=> (filemtime($rootA) ?: 0);
        });

        return $runs[0] ?? null;
    }

    /**
     * Keep only the most recently modified RETENTION_RUNS runs on disk.
     */
    public function rotate(): void
    {
        $root = $this->rootDirectory();
        if (! is_dir($root)) {
            return;
        }
        $dirs = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        if (count($dirs) <= self::RETENTION_RUNS) {
            return;
        }
        usort($dirs, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $excess = array_slice($dirs, self::RETENTION_RUNS);
        foreach ($excess as $dir) {
            $this->removeDirectory($dir);
        }
    }

    public function monotonicMsSinceStart(string $dir): int
    {
        $intent = $dir.DIRECTORY_SEPARATOR.'intent.json';
        if (! is_file($intent)) {
            return 0;
        }
        $mtime = filemtime($intent);
        if ($mtime === false) {
            return 0;
        }
        $now = microtime(true);

        return (int) max(0, (int) round(($now - $mtime) * 1000.0));
    }

    private function sanitizeRunId(string $runId): string
    {
        $trimmed = trim($runId);
        if ($trimmed === '' || preg_match('/[^A-Za-z0-9_\-:.]/', $trimmed) === 1) {
            throw new RuntimeException('rivals_forge_run_log_stream:invalid_run_id:'.$runId);
        }

        return $trimmed;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
