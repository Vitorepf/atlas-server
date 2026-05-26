<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use App\Services\Ai\Context\AtlasUnifiedRealityGraphService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * AURG · Temporal 4D Extension.
 *
 * Extends 3D AURG snapshots with a time axis. Records ticks of arbitrary
 * AURG snapshots into an append-only JSONL log; provides timeline,
 * stateAt(), traverseTime() and chain verification.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-aurg-temporal-4d.md
 *
 * Schemas:
 *   - atlas.aurg.temporal_tick.v1
 *   - atlas.aurg.temporal_snapshot.v1
 *
 * Invariantes:
 *   - append-only; ticks anteriores nunca mutam;
 *   - hash chain (prev_tick_hash -> tick_hash);
 *   - traverseTime bounded para evitar runaway.
 */
final class AtlasUnifiedRealityGraphTemporalService
{
    public const TICK_SCHEMA = 'atlas.aurg.temporal_tick.v1';

    public const SNAPSHOT_SCHEMA = 'atlas.aurg.temporal_snapshot.v1';

    public const KIND_SNAPSHOT_RECORDED = 'snapshot_recorded';

    public const KIND_NODE_ADDED = 'node_added';

    public const KIND_EDGE_ADDED = 'edge_added';

    public const KIND_NODE_REMOVED = 'node_removed';

    public const KIND_EDGE_REMOVED = 'edge_removed';

    public const KIND_RATIONALE_EVENT = 'rationale_event';

    public const VALID_KINDS = [
        self::KIND_SNAPSHOT_RECORDED,
        self::KIND_NODE_ADDED,
        self::KIND_EDGE_ADDED,
        self::KIND_NODE_REMOVED,
        self::KIND_EDGE_REMOVED,
        self::KIND_RATIONALE_EVENT,
    ];

    public const VALID_ACTORS = [
        'operator',
        'atlas',
        'cognitive_immune',
        'self_construction',
        'teos',
        'unknown',
    ];

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasUnifiedRealityGraphService $aurg,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/aurg')
            : sys_get_temp_dir().'/atlas/aurg';

        return $base.DIRECTORY_SEPARATOR.'temporal_ticks.jsonl';
    }

    /**
     * Append a tick. Idempotent in the sense that the caller computes
     * `tick_id` deterministically — duplicate tick_ids are rejected.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordTick(array $input): array
    {
        $kind = (string) ($input['kind'] ?? '');
        if (! in_array($kind, self::VALID_KINDS, true)) {
            throw new InvalidArgumentException("Unknown tick kind '{$kind}'.");
        }
        $actor = (string) ($input['actor'] ?? 'operator');
        if (! in_array($actor, self::VALID_ACTORS, true)) {
            throw new InvalidArgumentException("Unknown actor '{$actor}'.");
        }
        $snapshotHash = $input['snapshot_hash'] ?? null;
        if ($snapshotHash !== null && ! is_string($snapshotHash)) {
            throw new InvalidArgumentException('snapshot_hash must be a string or null.');
        }

        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $prev = $this->lastTick();
        $prevHash = $prev !== null ? ($prev['tick_hash'] ?? null) : null;

        $deltaSummary = $input['delta_summary'] ?? [
            'nodes_added' => [],
            'nodes_removed' => [],
            'edges_added' => [],
            'edges_removed' => [],
        ];
        $rationale = (string) ($input['rationale'] ?? '');

        $tickId = 'tick_'.substr(
            hash('sha256', $at.'|'.($snapshotHash ?? '').'|'.($prevHash ?? '')),
            0,
            12
        );

        $tick = [
            'schema_version' => self::TICK_SCHEMA,
            'tick_id' => $tickId,
            'at' => $at,
            'actor' => $actor,
            'kind' => $kind,
            'snapshot_hash' => $snapshotHash,
            'delta_summary' => $deltaSummary,
            'rationale' => $rationale,
            'prev_tick_hash' => $prevHash,
        ];
        $tick['tick_hash'] = $this->tickHash($tick);

        $this->appendTick($tick);

        return $tick;
    }

    /**
     * Full timeline (newest last). Bounded by $limit.
     *
     * @return array<string,mixed>
     */
    public function timeline(int $limit = 100): array
    {
        $ticks = $this->readTicks();
        usort($ticks, static fn ($a, $b) => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));
        if ($limit > 0 && count($ticks) > $limit) {
            $ticks = array_slice($ticks, -$limit);
        }
        $verification = $this->verifyChain();

        $envelope = [
            'schema_version' => self::SNAPSHOT_SCHEMA,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'tick_count' => count($ticks),
            'first_tick_at' => $ticks[0]['at'] ?? null,
            'last_tick_at' => $ticks[count($ticks) - 1]['at'] ?? null,
            'ticks' => $ticks,
            'chain_intact' => $verification['chain_intact'],
            'chain_break_at' => $verification['chain_break_at'],
        ];
        $envelope['temporal_hash'] = $this->temporalHash($envelope);

        return $envelope;
    }

    /**
     * Returns the most-recent tick whose `at` is <= $iso. Returns null when none.
     *
     * @return array<string,mixed>|null
     */
    public function stateAt(string $iso): ?array
    {
        $target = strtotime($iso);
        if ($target === false) {
            throw new InvalidArgumentException("Unparseable ISO-8601: '{$iso}'.");
        }
        $best = null;
        foreach ($this->readTicks() as $t) {
            $tAt = strtotime((string) ($t['at'] ?? ''));
            if ($tAt === false || $tAt > $target) {
                continue;
            }
            if ($best === null || $tAt > strtotime((string) $best['at'])) {
                $best = $t;
            }
        }

        return $best;
    }

    /**
     * Returns ticks within [from, to], up to $maxTicks (defensive cap).
     *
     * @return list<array<string,mixed>>
     */
    public function traverseTime(string $fromIso, string $toIso, int $maxTicks = 1000): array
    {
        $from = strtotime($fromIso);
        $to = strtotime($toIso);
        if ($from === false || $to === false) {
            throw new InvalidArgumentException('Unparseable ISO bounds.');
        }
        if ($maxTicks <= 0) {
            $maxTicks = 1000;
        }
        $out = [];
        foreach ($this->readTicks() as $t) {
            $tAt = strtotime((string) ($t['at'] ?? ''));
            if ($tAt === false) {
                continue;
            }
            if ($tAt < $from || $tAt > $to) {
                continue;
            }
            $out[] = $t;
            if (count($out) >= $maxTicks) {
                break;
            }
        }
        usort($out, static fn ($a, $b) => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));

        return $out;
    }

    /**
     * Walk the chain front-to-back, verifying prev_tick_hash equals the
     * preceding tick's tick_hash.
     *
     * @return array{chain_intact:bool, chain_break_at:?string, ticks_walked:int}
     */
    public function verifyChain(): array
    {
        $ticks = $this->readTicks();
        usort($ticks, static fn ($a, $b) => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));
        $prevHash = null;
        $walked = 0;
        foreach ($ticks as $t) {
            $expectedPrev = $t['prev_tick_hash'] ?? null;
            if ($expectedPrev !== $prevHash) {
                return [
                    'chain_intact' => false,
                    'chain_break_at' => (string) ($t['tick_id'] ?? '<unknown>'),
                    'ticks_walked' => $walked,
                ];
            }
            $prevHash = $t['tick_hash'] ?? null;
            $walked++;
        }

        return [
            'chain_intact' => true,
            'chain_break_at' => null,
            'ticks_walked' => $walked,
        ];
    }

    /**
     * Capture the current 3D AURG snapshot built from supplied nodes/edges
     * and record it as a tick. Convenience: caller still supplies nodes/edges.
     *
     * @param  list<array<string,mixed>>  $nodes
     * @param  list<array<string,mixed>>  $edges
     */
    public function captureSnapshot(array $nodes, array $edges, string $actor = 'operator', string $rationale = ''): array
    {
        $snapshot = $this->aurg->buildSnapshot($nodes, $edges);
        $tick = $this->recordTick([
            'kind' => self::KIND_SNAPSHOT_RECORDED,
            'actor' => $actor,
            'snapshot_hash' => $snapshot['snapshot_hash'] ?? null,
            'rationale' => $rationale,
        ]);

        return [
            'snapshot' => $snapshot,
            'tick' => $tick,
        ];
    }

    // ---------- internals ----------

    private function lastTick(): ?array
    {
        $ticks = $this->readTicks();
        if ($ticks === []) {
            return null;
        }
        usort($ticks, static fn ($a, $b) => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));

        return $ticks[count($ticks) - 1];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readTicks(): array
    {
        $path = $this->logPath();
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendTick(array $tick): void
    {
        $path = $this->logPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($tick, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    private function tickHash(array $tick): string
    {
        $canonical = [
            'schema' => self::TICK_SCHEMA,
            'tick_id' => $tick['tick_id'],
            'at' => $tick['at'],
            'actor' => $tick['actor'],
            'kind' => $tick['kind'],
            'snapshot_hash' => $tick['snapshot_hash'],
            'prev_tick_hash' => $tick['prev_tick_hash'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private function temporalHash(array $envelope): string
    {
        $canonical = [
            'schema' => self::SNAPSHOT_SCHEMA,
            'tick_ids' => array_map(static fn ($t) => $t['tick_id'] ?? null, $envelope['ticks']),
            'chain_intact' => $envelope['chain_intact'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
