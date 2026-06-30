<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

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

        // Duplicate-cycle_id fail-closed check (under LOCK_EX for true serialization).
        $this->ensureDirExists();
        $fh = @fopen($this->ledgerPath, 'a+');
        if ($fh === false) {
            throw new RuntimeException('Cortex episodic ledger cannot open '.$this->ledgerPath);
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new RuntimeException('Cortex episodic ledger cannot acquire LOCK_EX');
            }
            if ($this->cycleAlreadyRecorded($episode->cycleId)) {
                throw new RuntimeException('duplicate cycle_id: '.$episode->cycleId);
            }
            $payload = (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fseek($fh, 0, SEEK_END);
            fwrite($fh, $payload."\n");
            fflush($fh);
            @\fsync($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $episode;
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
        if (! is_file($this->ledgerPath)) {
            return 0;
        }

        return count(file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
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
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        usort($out, static function (array $a, array $b): int {
            $tsA = (int) ($a['captured_at'] ?? 0);
            $tsB = (int) ($b['captured_at'] ?? 0);

            return $tsA <=> $tsB ?: strcmp((string) ($a['cycle_id'] ?? ''), (string) ($b['cycle_id'] ?? ''));
        });

        return $out;
    }

    private function cycleAlreadyRecorded(string $cycleId): bool
    {
        if (! is_file($this->ledgerPath)) {
            return false;
        }
        foreach (file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded) && (string) ($decoded['cycle_id'] ?? '') === $cycleId) {
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

    private function ensureDirExists(): void
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
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
