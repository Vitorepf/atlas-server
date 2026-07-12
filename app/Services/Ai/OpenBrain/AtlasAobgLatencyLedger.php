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
     *   days:array<string,array{date:string,samples:int,ops:array<string,array<string,mixed>>}>
     * }
     */
    public function report(?string $day = null, int $days = 7): array
    {
        $dayKeys = $day !== null
            ? [$this->normalizeDay($day)]
            : $this->latestDays(max(1, $days));

        $reports = [];
        foreach ($dayKeys as $dayKey) {
            $rows = $this->readDay($dayKey);
            $reports[$dayKey] = $this->summarizeDay($dayKey, $rows);
        }

        return [
            'schema_version' => 'atlas.aobg.latency_report.v1',
            'status' => $this->sampleCount($reports) > 0 ? 'ok' : 'empty',
            'generated_at' => gmdate('c'),
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
