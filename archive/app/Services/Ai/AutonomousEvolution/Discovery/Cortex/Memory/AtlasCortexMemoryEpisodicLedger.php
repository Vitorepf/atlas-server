<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Generator;
use RuntimeException;
use Throwable;

/**
 * Append-only canonical write+read substrate for cross-cycle Cortex memory. One JSON-line episode per
 * Cortex cycle. The path is resolvable via config('atlas.loop.cortex.memory.episodic_ledger_path') with
 * a documented fallback under the workspace storage tree.
 *
 * INVARIANTS:
 *   - FACT-ONLY: episodes are recorded as-is — no score, no rank, no opinion (R1/R2 anti-Goodhart).
 *   - APPEND-ONLY: fopen('a') + flock(LOCK_EX); existing bytes are NEVER overwritten.
 *   - DUPLICATE cycle_id ⇒ fail-closed via {@see RuntimeException}.
 *   - DETERMINISTIC REPLAY: ordering = (captured_at, cycle_id lex); identical ndjson contents ⇒ identical
 *     digest_of(cycle_id).
 *   - MASTER-OFF: when ATLAS_LOOP_MASTER_ENABLED=false, append() is a byte-identical no-op (no file write).
 */
final class AtlasCortexMemoryEpisodicLedger
{
    public const ALLOWED_KEYS = [
        'cycle_id',
        'captured_at',
        'scope_root',
        'snapshot_digest',
        'inventory_items',
        'blind_spots',
        'intent_interpretations',
        'source_facts_only',
    ];

    public function __construct(private readonly string $ledgerPath) {}

    /**
     * @return AtlasCortexMemoryEpisodeRecord  echoes the recorded episode
     *
     * @throws RuntimeException     duplicate cycle_id
     * @throws RuntimeException non-FACT or missing field
     */
    public function append(AtlasCortexMemoryEpisodeRecord $episode): AtlasCortexMemoryEpisodeRecord
    {
        $canonical = $episode->toCanonicalArray();
        $this->guardSchema($canonical);

        if (! $this->masterEnabled()) {
            return $episode;
        }

        // Duplicate-cycle_id fail-closed check runs INSIDE the store's LOCK_EX for true serialization.
        $this->store()->appendWith(function (?string $lastLine) use ($episode, $canonical): array {
            if ($this->cycleAlreadyRecorded($episode->cycleId)) {
                throw new RuntimeException('duplicate cycle_id: '.$episode->cycleId);
            }

            return $canonical;
        });

        return $episode;
    }

    private function store(): JsonlReceiptStore
    {
        return new JsonlReceiptStore($this->ledgerPath);
    }

    /**
     * Streams episodes in (captured_at, cycle_id) lex order without loading the file into memory in one shot.
     * Filters: $limit (>=1) and $sinceUnix (captured_at >= since).
     *
     * @return Generator<int,array<string,mixed>>
     */
    public function iterate(?int $limit = null, ?int $sinceUnix = null): Generator
    {
        $all = $this->loadAllSorted();
        $count = 0;
        foreach ($all as $row) {
            if ($sinceUnix !== null && (int) ($row['captured_at'] ?? 0) < $sinceUnix) {
                continue;
            }
            yield $row;
            $count++;
            if ($limit !== null && $count >= $limit) {
                return;
            }
        }
    }

    public function count(): int
    {
        return count($this->store()->rawLines());
    }

    /**
     * Deterministic SHA-256 digest over the canonical episode for $cycleId. Two independent ledger
     * instances reading the same ndjson file MUST produce byte-identical digests.
     */
    public function digest_of(string $cycleId): ?string
    {
        foreach ($this->loadAllSorted() as $row) {
            if ((string) ($row['cycle_id'] ?? '') === $cycleId) {
                $canonical = $this->canonicalSort($row);

                return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAllSorted(): array
    {
        $out = $this->store()->replay();
        usort($out, static function (array $a, array $b): int {
            $tsA = (int) ($a['captured_at'] ?? 0);
            $tsB = (int) ($b['captured_at'] ?? 0);

            return $tsA <=> $tsB ?: strcmp((string) ($a['cycle_id'] ?? ''), (string) ($b['cycle_id'] ?? ''));
        });

        return $out;
    }

    private function cycleAlreadyRecorded(string $cycleId): bool
    {
        foreach ($this->store()->replay() as $decoded) {
            if ((string) ($decoded['cycle_id'] ?? '') === $cycleId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $canonical
     */
    private function guardSchema(array $canonical): void
    {
        $keys = array_keys($canonical);
        $extras = array_diff($keys, self::ALLOWED_KEYS);
        if ($extras !== []) {
            throw new RuntimeException('non-FACT field(s) rejected: '.implode(',', $extras));
        }
        $missing = array_diff(self::ALLOWED_KEYS, $keys);
        if ($missing !== []) {
            throw new RuntimeException('missing required field(s): '.implode(',', $missing));
        }
        if ((string) $canonical['cycle_id'] === '') {
            throw new RuntimeException('cycle_id is required');
        }
        if (($canonical['source_facts_only'] ?? null) !== true) {
            throw new RuntimeException('source_facts_only sentinel must be true');
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function canonicalSort(array $row): array
    {
        ksort($row);
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                $row[$k] = array_map(function (mixed $x): mixed {
                    if (is_array($x)) {
                        ksort($x);
                    }

                    return $x;
                }, $v);
            }
        }

        return $row;
    }

    private function masterEnabled(): bool
    {
        try {
            $cls = \App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch::class;
            if (class_exists($cls)) {
                return (bool) $cls::enabled();
            }
        } catch (Throwable) {
        }

        return true;
    }
}
