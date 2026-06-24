<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class AtlasLoopRollingWindowAggregator
{
    /** @var array<string,int> */
    private const WINDOW_HOURS = [
        '1h' => 1,
        '6h' => 6,
        '24h' => 24,
    ];

    /** @var array<string,true> */
    private const LIFECYCLE_KINDS = [
        'claim' => true,
        'lease' => true,
        'serve' => true,
        'report' => true,
        'merge' => true,
    ];

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array{
     *     buckets:list<array{
     *         window_label:string,
     *         window_start_iso:string,
     *         window_end_iso:string,
     *         counts:array<string,int>,
     *         durations_ms:array<string,array{p50:int|null,p95:int|null,n:int}>,
     *         cost_sum_micros:int,
     *         anomaly_count:int
     *     }>
     * }
     */
    public function aggregate(array $facts, string $nowIso): array
    {
        $now = new DateTimeImmutable($nowIso, new DateTimeZone('UTC'));

        $buckets = [];
        foreach (self::WINDOW_HOURS as $label => $hours) {
            $buckets[] = $this->aggregateWindow($facts, $now, $label, $hours);
        }

        return ['buckets' => $buckets];
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array{
     *     window_label:string,
     *     window_start_iso:string,
     *     window_end_iso:string,
     *     counts:array<string,int>,
     *     durations_ms:array<string,array{p50:int|null,p95:int|null,n:int}>,
     *     cost_sum_micros:int,
     *     anomaly_count:int
     * }
     */
    private function aggregateWindow(array $facts, DateTimeImmutable $now, string $label, int $hours): array
    {
        $windowStart = $this->floorWindowStart($now, $hours);
        $windowEnd = $windowStart->add(new DateInterval('PT'.$hours.'H'));
        $cutoff = $now->sub(new DateInterval('PT'.$hours.'H'));
        $counts = [
            'claim' => 0,
            'lease' => 0,
            'serve' => 0,
            'report' => 0,
            'merge' => 0,
            'telemetry-cost' => 0,
            'anomaly' => 0,
        ];
        $costSumMicros = 0;
        $anomalyCount = 0;
        $byCycle = [];

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $occurredAtIso = trim((string) ($fact['occurred_at_iso'] ?? ''));
            $cycleId = trim((string) ($fact['cycle_id'] ?? ''));
            $kind = trim((string) ($fact['kind'] ?? ''));
            $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];

            if ($occurredAtIso === '' || $cycleId === '') {
                continue;
            }

            try {
                $occurredAt = new DateTimeImmutable($occurredAtIso);
            } catch (\Exception) {
                continue;
            }

            $occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
            if ($occurredAt < $cutoff || $occurredAt > $now) {
                continue;
            }

            if (! isset(self::LIFECYCLE_KINDS[$kind])) {
                continue;
            }

            $counts[$kind]++;
            $byCycle[$cycleId][$kind] = $occurredAt;

            if (isset($payload['cost_micros']) && is_numeric($payload['cost_micros'])) {
                $counts['telemetry-cost']++;
                $costSumMicros += (int) $payload['cost_micros'];
            }

            $anomalyIncrement = 0;
            if (isset($payload['anomaly_count']) && is_numeric($payload['anomaly_count'])) {
                $anomalyIncrement = max(0, (int) $payload['anomaly_count']);
            } elseif (($payload['anomaly'] ?? false) === true) {
                $anomalyIncrement = 1;
            }

            if ($anomalyIncrement > 0) {
                $counts['anomaly']++;
                $anomalyCount += $anomalyIncrement;
            }
        }

        return [
            'window_label' => $label,
            'window_start_iso' => $windowStart->format(DATE_ATOM),
            'window_end_iso' => $windowEnd->format(DATE_ATOM),
            'counts' => $counts,
            'durations_ms' => [
                'claim_to_lease' => $this->durationStats($byCycle, 'claim', 'lease'),
                'lease_to_serve' => $this->durationStats($byCycle, 'lease', 'serve'),
                'serve_to_report' => $this->durationStats($byCycle, 'serve', 'report'),
                'report_to_merge' => $this->durationStats($byCycle, 'report', 'merge'),
            ],
            'cost_sum_micros' => $costSumMicros,
            'anomaly_count' => $anomalyCount,
        ];
    }

    private function floorWindowStart(DateTimeImmutable $now, int $hours): DateTimeImmutable
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $hour = (int) $utc->format('G');
        $flooredHour = intdiv($hour, $hours) * $hours;

        return $utc->setTime($flooredHour, 0, 0);
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

        sort($durations, SORT_NUMERIC);
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
