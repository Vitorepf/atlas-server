<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\JsonFileStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Event Stream (v2 canonical path).
 *
 * Append-only JSONL stream rooted at the v2 canonical run path
 * (`/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/events.jsonl`).
 *
 * Distinct from the v1 `RivalsForgeRunLogStreamService` (which writes to
 * `storage/app/rivals-forge-runs/`) — kept separate so operator-side
 * tooling can always find a run under the canonical Atlas-rivals path.
 */
final class AtlasForgeRivalsEventStream
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.event_stream.v1';

    /** @var list<string> Canonical event kinds. */
    public const KINDS = [
        'run_started',
        'step_started',
        'preflight',
        'dry_run',
        'provider_started',
        'provider_stdout_chunk',
        'provider_stderr_chunk',
        'provider_timeout_warning',
        'provider_finished',
        'after_clean_check',
        'evidence_pack',
        'heartbeat',
        'blocked',
        'stalled',
        'final_report',
    ];

    /** @var array<string,int> Start timestamps per-run, set lazily on first write. */
    private array $startMonotonicMs = [];

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    public function start(string $runId, array $intent = []): string
    {
        $paths = $this->paths->paths($runId);
        $this->ensureDir($paths['base']);
        $this->ensureDir($paths['evidence']);

        $intentPayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'intent' => $intent,
            'started_at' => $this->nowIso(),
        ];
        $intentJsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $intentBlob = json_encode($intentPayload, $intentJsonFlags) ?: '{}';

        JsonFileStore::write($paths['base'].'/intent.json', $intentPayload, $intentJsonFlags, 0, 0o755);

        $this->event($runId, 'run_started', [
            'run_id' => $paths['run_id'],
            'intent_hash' => hash('sha256', $intentBlob),
        ]);

        return $paths['base'];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function event(string $runId, string $kind, array $payload = []): void
    {
        $paths = $this->paths->paths($runId);
        $this->ensureDir($paths['base']);
        $jsonl = $paths['events_jsonl'];

        if (! isset($this->startMonotonicMs[$paths['run_id']])) {
            $this->startMonotonicMs[$paths['run_id']] = (int) (hrtime(true) / 1_000_000);
        }
        $nowMs = (int) (hrtime(true) / 1_000_000);
        $monotonicMs = $nowMs - $this->startMonotonicMs[$paths['run_id']];

        $record = [
            'ts' => $this->nowIso(),
            'monotonic_ms_since_start' => max(0, $monotonicMs),
            'kind' => $kind,
            'payload' => $payload,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        AppendOnlyJsonlStore::appendEncodedLineSilently($jsonl, $line, FILE_APPEND | LOCK_EX, 0o755);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tail(string $runId, int $limit = 200): array
    {
        $jsonl = $this->paths->paths($runId)['events_jsonl'];
        $events = AppendOnlyJsonlStore::read($jsonl);

        return array_slice($events, -$limit);
    }

    public function lastEventAt(string $runId): ?DateTimeImmutable
    {
        $events = $this->tail($runId, 1);
        if ($events === []) {
            return null;
        }
        $ts = (string) ($events[0]['ts'] ?? '');
        if ($ts === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($ts);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
