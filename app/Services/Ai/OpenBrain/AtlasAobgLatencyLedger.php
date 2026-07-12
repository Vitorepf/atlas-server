<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrain;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AtlasAobgLatencyLedger
{
    public const OP_PACK = 'pack';

    public const OP_RECALL = 'recall';

    public const OP_HOOK = 'hook';

    public const OPS = [self::OP_PACK, self::OP_RECALL, self::OP_HOOK];

    public const DEFAULT_RELATIVE_DIR = 'atlas/aobg/latency-ledger';

    public function __construct(private readonly ?string $rootDir = null) {}

    public function recordPack(float $ms, array $pack): void
    {
        $counts = is_array($pack['counts'] ?? null) ? $pack['counts'] : [];
        $refs = (int) ($counts['code_graph'] ?? 0)
            + (int) ($counts['memory'] ?? 0)
            + (int) ($counts['reality_graph_paths'] ?? 0);

        if ($refs === 0) {
            $refs = count((array) ($pack['code_graph'] ?? []))
                + count((array) ($pack['memory'] ?? []))
                + count((array) ($pack['reality_graph_paths'] ?? []));
        }

        $budget = data_get($pack, 'budget.total_chars', data_get($pack, 'budget.requested_total_chars'));

        $this->record(self::OP_PACK, $ms, $refs, is_numeric($budget) ? (int) $budget : null);
    }

    public function recordRecall(float $ms, array $recall): void
    {
        $items = (array) ($recall['recall'] ?? []);
        $budget = data_get($recall, 'summary.budget_chars');

        $this->record(
            self::OP_RECALL,
            $ms,
            count($items),
            is_numeric($budget) ? (int) $budget : null,
        );
    }

    public function recordHook(float $ms, int $refs = 0): void
    {
        $this->record(self::OP_HOOK, $ms, $refs);
    }

    public function record(string $op, float $ms, int $refs = 0, ?int $budgetChars = null, ?string $ts = null): void
    {
        try {
            $op = trim($op);
            if (! in_array($op, self::OPS, true)) {
                return;
            }

            $ts = $this->normalizeTimestamp($ts);
            AppendOnlyJsonlStore::append($this->pathForDay(substr($ts, 0, 10)), [
                'op' => $op,
                'ms' => round(max(0.0, $ms), 3),
                'refs' => max(0, $refs),
                'budget_chars' => $budgetChars === null ? null : max(0, $budgetChars),
                'ts' => $ts,
            ]);
        } catch (Throwable) {
            // Latency telemetry is measure-only; it must never break context retrieval.
        }
    }

    public function pathForDay(?string $day = null): string
    {
        $day = $this->normalizeDay($day ?? gmdate('Y-m-d'));

        return rtrim($this->rootDir ?? storage_path(self::DEFAULT_RELATIVE_DIR), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$day.'.jsonl';
    }

    /**
     * @return array{
     *   schema_version:string,
     *   status:string,
     *   generated_at:string,
     *   window_1d:array<string,mixed>,
     *   ops:array<string,array<string,mixed>>,
     *   trend:array<string,mixed>,
     *   days:array<string,array{date:string,samples:int,ops:array<string,array<string,mixed>>}>
     * }
     */
    public function report(?string $day = null, int $days = 7): array
    {
        $windowDay = $day !== null ? $this->normalizeDay($day) : gmdate('Y-m-d');
        $dayKeys = $day !== null
            ? [$windowDay]
            : $this->latestDays(max(1, $days));

        $reports = [];
        foreach ($dayKeys as $dayKey) {
            $rows = $this->readDay($dayKey);
            $reports[$dayKey] = $this->summarizeDay($dayKey, $rows);
        }
        $windowSummary = $reports[$windowDay] ?? $this->summarizeDay($windowDay, $this->readDay($windowDay));

        return [
            'schema_version' => 'atlas.aobg.latency_report.v1',
            'status' => $this->sampleCount($reports) > 0 ? 'ok' : 'empty',
            'generated_at' => gmdate('c'),
            'window_1d' => $this->windowReport($windowSummary),
            'ops' => $windowSummary['ops'],
            'trend' => $this->trendReport($reports),
            'days' => $reports,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function readDay(?string $day = null): array
    {
        try {
            return AppendOnlyJsonlStore::read($this->pathForDay($day));
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function summarizeDay(string $day, array $rows): array
    {
        $byOp = [];
        foreach ($rows as $row) {
            $op = (string) ($row['op'] ?? '');
            if (! in_array($op, self::OPS, true) || ! is_numeric($row['ms'] ?? null)) {
                continue;
            }
            $byOp[$op][] = (float) $row['ms'];
        }

        $ops = [];
        foreach ($byOp as $op => $values) {
            sort($values);
            $ops[$op] = [
                'samples' => count($values),
                'p50_ms' => $this->roundOrNull($this->percentile($values, 0.50)),
                'p95_ms' => $this->roundOrNull($this->percentile($values, 0.95)),
                'max_ms' => $values === [] ? null : $this->roundOrNull(max($values)),
            ];
        }
        ksort($ops);

        return [
            'date' => $day,
            'samples' => array_sum(array_map(static fn (array $stats): int => (int) $stats['samples'], $ops)),
            'ops' => $ops,
        ];
    }

    /** @param array{date:string,samples:int,ops:array<string,array<string,mixed>>} $summary */
    private function windowReport(array $summary): array
    {
        return $summary + [
            'status' => $summary['samples'] > 0 ? 'ok' : 'insufficient_signal',
            'reason' => $summary['samples'] > 0 ? 'samples_present' : 'insufficient_1d_window',
        ];
    }

    /** @param array<string,array{date:string,samples:int,ops:array<string,array<string,mixed>>}> $reports */
    private function trendReport(array $reports): array
    {
        ksort($reports);

        $pointsByOp = [];
        foreach ($reports as $date => $report) {
            foreach ($report['ops'] as $op => $stats) {
                $pointsByOp[$op][] = [
                    'date' => (string) $date,
                    'samples' => (int) ($stats['samples'] ?? 0),
                    'p50_ms' => $stats['p50_ms'] ?? null,
                    'p95_ms' => $stats['p95_ms'] ?? null,
                ];
            }
        }
        ksort($pointsByOp);

        $ops = [];
        foreach ($pointsByOp as $op => $points) {
            $latest = $points[array_key_last($points)] ?? null;
            $previous = count($points) > 1 ? $points[count($points) - 2] : null;
            $latestP95 = is_numeric($latest['p95_ms'] ?? null) ? (float) $latest['p95_ms'] : null;
            $previousP95 = is_numeric($previous['p95_ms'] ?? null) ? (float) $previous['p95_ms'] : null;
            $delta = $latestP95 !== null && $previousP95 !== null
                ? $this->roundOrNull($latestP95 - $previousP95)
                : null;

            $ops[$op] = [
                'points' => $points,
                'latest_p95_ms' => $this->roundOrNull($latestP95),
                'previous_p95_ms' => $this->roundOrNull($previousP95),
                'delta_p95_ms' => $delta,
                'direction' => $this->trendDirection($delta),
            ];
        }

        return [
            'schema_version' => 'atlas.aobg.latency_trend.v1',
            'ops' => $ops,
        ];
    }

    private function trendDirection(?float $delta): string
    {
        if ($delta === null) {
            return 'unknown';
        }

        return $delta < 0.0 ? 'improved' : ($delta > 0.0 ? 'regressed' : 'flat');
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

    private function normalizeTimestamp(?string $ts): string
    {
        if ($ts === null || trim($ts) === '') {
            return gmdate('c');
        }

        try {
            return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone('UTC'))->format('c');
        } catch (Throwable) {
            return gmdate('c');
        }
    }

    private function normalizeDay(string $day): string
    {
        $day = trim($day);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : gmdate('Y-m-d');
    }

    /** @return list<string> */
    private function latestDays(int $days): array
    {
        $dir = rtrim($this->rootDir ?? storage_path(self::DEFAULT_RELATIVE_DIR), DIRECTORY_SEPARATOR);
        $files = is_dir($dir) ? glob($dir.DIRECTORY_SEPARATOR.'*.jsonl') : [];
        $dayKeys = [];
        foreach (is_array($files) ? $files : [] as $file) {
            $name = basename((string) $file, '.jsonl');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) === 1) {
                $dayKeys[] = $name;
            }
        }

        rsort($dayKeys);
        $dayKeys = array_slice($dayKeys, 0, $days);
        sort($dayKeys);

        return $dayKeys;
    }

    /** @param array<string,array{samples:int}> $reports */
    private function sampleCount(array $reports): int
    {
        return array_sum(array_map(static fn (array $report): int => (int) $report['samples'], $reports));
    }

    private function roundOrNull(?float $value): ?float
    {
        return $value === null ? null : round($value, 3);
    }
}
