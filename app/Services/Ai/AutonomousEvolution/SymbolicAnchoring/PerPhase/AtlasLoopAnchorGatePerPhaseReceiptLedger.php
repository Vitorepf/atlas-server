<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Append-only JSONL ledger of per-phase anchor-gate enforcement verdicts.
 *
 * Storage: `<root>/YYYY-MM-DD.jsonl`. Each `recordVerdict()` call appends ONE line carrying
 * { loop_cycle_id, phase, decision, measured_density, required_density, missing_anchor_kinds,
 *   payload_sha256, ts_utc }. When `enabled=false`, recordVerdict() is a no-op (no file is created).
 *
 * Raw line IO delegates to {@see AppendOnlyJsonlStore::appendSilently()} (best-effort append:
 * unopenable file is ignored and recordVerdict() returns null, matching the prior fopen policy);
 * the day-partitioned path and domain payload shaping stay here.
 */
final class AtlasLoopAnchorGatePerPhaseReceiptLedger
{
    public function __construct(
        private readonly string $rootDir,
        private readonly bool $enabled = true,
    ) {
        if ($this->enabled && ! is_dir($this->rootDir)) {
            @mkdir($this->rootDir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $payload  the emission payload the verdict was about
     * @return array<string,mixed>|null
     */
    public function recordVerdict(
        string $loopCycleId,
        string $phase,
        EnforcementVerdict $verdict,
        array $payload,
        ?string $tsUtc = null,
    ): ?array {
        if (! $this->enabled) {
            return null;
        }

        $tsUtc = $tsUtc ?? gmdate('Y-m-d\TH:i:s\Z');
        $day = substr($tsUtc, 0, 10);
        $path = $this->rootDir.'/'.$day.'.jsonl';

        $row = [
            'loop_cycle_id' => $loopCycleId,
            'phase' => $phase,
            'decision' => $verdict->allow ? 'allow' : 'refuse',
            'reason_code' => $verdict->reasonCode,
            'measured_density' => $verdict->measuredDensity,
            'required_density' => $verdict->requiredDensity,
            'missing_anchor_kinds' => array_values($verdict->missingAnchorKinds),
            'payload_sha256' => hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'ts_utc' => $tsUtc,
        ];

        // Best-effort append: an unopenable file yields null (prior fopen policy preserved),
        // not a throw — verdicts are advisory telemetry, never fail the cycle over a log file.
        AppendOnlyJsonlStore::appendSilently($path, $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentForCycle(string $loopCycleId): array
    {
        return array_values(array_filter($this->readAll(), static fn (array $r): bool => (string) ($r['loop_cycle_id'] ?? '') === $loopCycleId));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentForPhase(string $phase, int $limit): array
    {
        $rows = array_values(array_filter($this->readAll(), static fn (array $r): bool => (string) ($r['phase'] ?? '') === $phase));

        if ($limit <= 0) {
            return [];
        }

        return array_values(array_slice($rows, -$limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        if (! is_dir($this->rootDir)) {
            return [];
        }
        $files = glob($this->rootDir.'/*.jsonl') ?: [];
        sort($files, SORT_STRING);
        $rows = [];
        foreach ($files as $file) {
            // read() skips corrupt/empty/non-array lines — identical to the prior inline loop.
            $rows = array_merge($rows, AppendOnlyJsonlStore::read($file));
        }

        return $rows;
    }
}
