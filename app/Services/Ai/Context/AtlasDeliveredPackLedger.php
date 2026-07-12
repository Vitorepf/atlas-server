<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Throwable;

/**
 * COM-01 — append-only ledger of delivered context-pack refs keyed by context_pack_hash.
 *
 * Provider-safe: refs, budgets, policy snapshot and timestamps only — no raw source text.
 * Fail-open: write failures never break pack assembly.
 */
final class AtlasDeliveredPackLedger
{
    public const SCHEMA = 'atlas.aobg.delivered_pack_ledger.v2';

    public const DEFAULT_RETENTION_HOURS = 168;

    public function __construct(
        private readonly string $path,
        private readonly int $retentionHours = self::DEFAULT_RETENTION_HOURS,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config(
                'atlas.aobg.delivered_pack_ledger.path',
                storage_path('atlas/aobg/delivered-pack-ledger.jsonl'),
            ),
            max(1, (int) config('atlas.aobg.delivered_pack_ledger.retention_hours', self::DEFAULT_RETENTION_HOURS)),
        );
    }

    /**
     * @param  array<string,mixed>  $pack
     */
    public function record(array $pack): void
    {
        try {
            $hash = trim((string) ($pack['context_pack_hash'] ?? ''));
            if ($hash === '') {
                return;
            }

            $entry = [
                'schema' => self::SCHEMA,
                'context_pack_hash' => $hash,
                'delivered_refs' => AtlasCanonicalContextRef::deliveredFromPack($pack),
                'budgets' => (array) ($pack['budget'] ?? []),
                'policy_snapshot' => (array) ($pack['context_delivery_policy'] ?? []),
                'timings_ms' => $this->normalizeTimings((array) ($pack['timings_ms'] ?? [])),
                'cache' => (array) ($pack['cache'] ?? []),
                'ts' => (string) ($pack['generated_at'] ?? now()->toJSON()),
            ];

            (new JsonlReceiptStore($this->path))
                ->append($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable) {
            // Fail-open: ledger write must never break pack assembly.
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lookup(string $contextPackHash): ?array
    {
        $contextPackHash = trim($contextPackHash);
        if ($contextPackHash === '') {
            return null;
        }

        $match = null;
        foreach ($this->pruneRows((new JsonlReceiptStore($this->path))->replay()) as $row) {
            if (($row['context_pack_hash'] ?? null) === $contextPackHash) {
                $match = $row;
            }
        }

        return $match;
    }

    /**
     * @param  array<int,string>  $contextPackHashes
     * @return array{delivered_refs:array<int,string>,entries:array<int,array<string,mixed>>}
     */
    public function lookupMany(array $contextPackHashes): array
    {
        $wanted = AtlasCanonicalContextRef::uniqueStrings($contextPackHashes);
        if ($wanted === []) {
            return ['delivered_refs' => [], 'entries' => []];
        }

        $wantedSet = array_fill_keys($wanted, true);
        $entries = [];
        $refs = [];

        foreach ($this->pruneRows((new JsonlReceiptStore($this->path))->replay()) as $row) {
            $hash = (string) ($row['context_pack_hash'] ?? '');
            if ($hash === '' || ! isset($wantedSet[$hash])) {
                continue;
            }
            $entries[] = $row;
            foreach ((array) ($row['delivered_refs'] ?? []) as $ref) {
                if (is_string($ref) && trim($ref) !== '') {
                    $refs[] = trim($ref);
                }
            }
        }

        return [
            'delivered_refs' => AtlasCanonicalContextRef::uniqueStrings($refs),
            'entries' => $entries,
        ];
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string,mixed>
     */
    public function timingReport(int $days = 7): array
    {
        $rows = $this->pruneRows((new JsonlReceiptStore($this->path))->replay());
        $days = max(1, $days);
        $dayKeys = [];
        foreach ($rows as $row) {
            $day = substr((string) ($row['ts'] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $dayKeys[$day] = true;
            }
        }
        $dayKeys = array_keys($dayKeys);
        rsort($dayKeys);
        $dayKeys = array_slice($dayKeys, 0, $days);
        sort($dayKeys);
        $wantedDays = array_fill_keys($dayKeys, true);

        $byDay = [];
        foreach ($rows as $row) {
            $day = substr((string) ($row['ts'] ?? ''), 0, 10);
            if (! isset($wantedDays[$day])) {
                continue;
            }
            foreach ($this->normalizeTimings((array) ($row['timings_ms'] ?? [])) as $section => $ms) {
                $byDay[$day][$section][] = $ms;
            }
        }

        $reports = [];
        foreach ($dayKeys as $day) {
            $sections = [];
            foreach ($byDay[$day] ?? [] as $section => $values) {
                sort($values);
                $sections[$section] = [
                    'samples' => count($values),
                    'p50_ms' => $this->roundOrNull($this->percentile($values, 0.50)),
                    'p95_ms' => $this->roundOrNull($this->percentile($values, 0.95)),
                ];
            }
            ksort($sections);
            $reports[$day] = [
                'date' => $day,
                'samples' => array_sum(array_map(static fn (array $stats): int => (int) $stats['samples'], $sections)),
                'sections' => $sections,
            ];
        }

        $latest = $reports === [] ? ['date' => null, 'samples' => 0, 'sections' => []] : $reports[array_key_last($reports)];

        return [
            'schema_version' => 'atlas.aobg.delivered_pack_timing_report.v1',
            'status' => ((int) ($latest['samples'] ?? 0)) > 0 ? 'ok' : 'empty',
            'generated_at' => now()->toJSON(),
            'window' => $latest,
            'sections' => (array) ($latest['sections'] ?? []),
            'trend' => $this->timingTrend($reports),
            'days' => $reports,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function pruneRows(array $rows): array
    {
        $cutoff = now()->subHours($this->retentionHours);
        $kept = [];

        foreach ($rows as $row) {
            $ts = trim((string) ($row['ts'] ?? ''));
            if ($ts === '') {
                $kept[] = $row;

                continue;
            }

            try {
                if (now()->parse($ts)->greaterThanOrEqualTo($cutoff)) {
                    $kept[] = $row;
                }
            } catch (Throwable) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string,mixed>  $timings
     * @return array<string,float>
     */
    private function normalizeTimings(array $timings): array
    {
        $normalized = [];
        foreach (['code_graph', 'reality_graph', 'memory', 'total'] as $section) {
            $value = $timings[$section] ?? null;
            if (is_numeric($value)) {
                $normalized[$section] = round(max(0.0, (float) $value), 3);
            }
        }

        return $normalized;
    }

    /** @param array<string,array{date:string,samples:int,sections:array<string,array<string,mixed>>}> $reports */
    private function timingTrend(array $reports): array
    {
        $pointsBySection = [];
        foreach ($reports as $date => $report) {
            foreach ((array) ($report['sections'] ?? []) as $section => $stats) {
                $pointsBySection[$section][] = [
                    'date' => (string) $date,
                    'samples' => (int) ($stats['samples'] ?? 0),
                    'p50_ms' => $stats['p50_ms'] ?? null,
                    'p95_ms' => $stats['p95_ms'] ?? null,
                ];
            }
        }
        ksort($pointsBySection);

        $sections = [];
        foreach ($pointsBySection as $section => $points) {
            $latest = $points[array_key_last($points)] ?? null;
            $previous = count($points) > 1 ? $points[count($points) - 2] : null;
            $latestP95 = is_numeric($latest['p95_ms'] ?? null) ? (float) $latest['p95_ms'] : null;
            $previousP95 = is_numeric($previous['p95_ms'] ?? null) ? (float) $previous['p95_ms'] : null;
            $delta = $latestP95 !== null && $previousP95 !== null
                ? $this->roundOrNull($latestP95 - $previousP95)
                : null;
            $sections[$section] = [
                'points' => $points,
                'latest_p95_ms' => $this->roundOrNull($latestP95),
                'previous_p95_ms' => $this->roundOrNull($previousP95),
                'delta_p95_ms' => $delta,
                'direction' => $delta === null ? 'unknown' : ($delta < 0.0 ? 'improved' : ($delta > 0.0 ? 'regressed' : 'flat')),
            ];
        }

        return [
            'schema_version' => 'atlas.aobg.delivered_pack_timing_trend.v1',
            'sections' => $sections,
        ];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $q): ?float
    {
        if ($values === []) {
            return null;
        }
        if (count($values) === 1) {
            return $values[0];
        }

        $rank = $q * (count($values) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $values[$low];
        }

        return $values[$low] + (($values[$high] - $values[$low]) * ($rank - $low));
    }

    private function roundOrNull(?float $value): ?float
    {
        return $value === null ? null : round($value, 3);
    }
}
