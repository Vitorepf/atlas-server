<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

use DateInterval;
use DateTimeImmutable;

final class AtlasLoopTelemetryFactWindowAggregator
{
    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array{
     *   counts:array{claim:int,lease:int,serve:int,report:int,merge:int},
     *   durations_ms:array{
     *     claim_to_lease:array{p50:int|null,p95:int|null,n:int},
     *     lease_to_serve:array{p50:int|null,p95:int|null,n:int},
     *     serve_to_report:array{p50:int|null,p95:int|null,n:int},
     *     report_to_merge:array{p50:int|null,p95:int|null,n:int}
     *   }
     * }
     */
    public function aggregate(array $facts, string $nowIso, int $windowMinutes): array
    {
        $now = new DateTimeImmutable($nowIso);
        $cutoff = $now->sub(new DateInterval('PT'.max(0, $windowMinutes).'M'));
        $counts = [
            'claim' => 0,
            'lease' => 0,
            'serve' => 0,
            'report' => 0,
            'merge' => 0,
        ];
        $byCycle = [];

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $kind = trim((string) ($fact['kind'] ?? ''));
            $cycleId = trim((string) ($fact['cycle_id'] ?? ''));
            $occurredAtIso = trim((string) ($fact['occurred_at_iso'] ?? ''));

            if (! array_key_exists($kind, $counts) || $cycleId === '' || $occurredAtIso === '') {
                continue;
            }

            try {
                $occurredAt = new DateTimeImmutable($occurredAtIso);
            } catch (\Exception) {
                continue;
            }

            if ($occurredAt < $cutoff || $occurredAt > $now) {
                continue;
            }

            $counts[$kind]++;
            $byCycle[$cycleId][$kind] = $occurredAt;
        }

        return [
            'counts' => $counts,
            'durations_ms' => [
                'claim_to_lease' => $this->durationStats($byCycle, 'claim', 'lease'),
                'lease_to_serve' => $this->durationStats($byCycle, 'lease', 'serve'),
                'serve_to_report' => $this->durationStats($byCycle, 'serve', 'report'),
                'report_to_merge' => $this->durationStats($byCycle, 'report', 'merge'),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, DateTimeImmutable>>  $byCycle
     * @return array{p50:int|null,p95:int|null,n:int}
     */
    private function durationStats(array $byCycle, string $from, string $to): array
    {
        $durations = [];

        foreach ($byCycle as $events) {
            if (! isset($events[$from], $events[$to])) {
                continue;
            }

            $deltaMs = (int) (($events[$to]->getTimestamp() - $events[$from]->getTimestamp()) * 1000);
            if ($deltaMs < 0) {
                continue;
            }

            $durations[] = $deltaMs;
        }

        sort($durations);
        $count = count($durations);

        return [
            'p50' => $count > 0 ? $this->percentile($durations, 0.50) : null,
            'p95' => $count > 0 ? $this->percentile($durations, 0.95) : null,
            'n' => $count,
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, float $quantile): int
    {
        $count = count($values);
        $index = max(0, min($count - 1, (int) ceil($count * $quantile) - 1));

        return $values[$index];
    }
}
