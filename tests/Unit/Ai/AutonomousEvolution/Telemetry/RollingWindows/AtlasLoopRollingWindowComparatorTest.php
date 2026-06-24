<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry\RollingWindows;

use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowComparator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasLoopRollingWindowComparatorTest extends TestCase
{
    #[Test]
    public function it_emits_fact_deltas_without_scores_or_normalization(): void
    {
        $comparator = new AtlasLoopRollingWindowComparator;

        $result = $comparator->compare(
            $this->bucket('1h', '2026-06-24T13:00:00+00:00', '2026-06-24T14:00:00+00:00', [
                'claim' => 4,
                'merge' => 1,
            ], [
                'claim_to_lease' => ['p50' => 2000, 'p95' => 3000],
            ], 80, 2),
            $this->bucket('1h', '2026-06-24T12:00:00+00:00', '2026-06-24T13:00:00+00:00', [
                'claim' => 2,
            ], [
                'claim_to_lease' => ['p50' => 1000, 'p95' => 1500],
            ], 30, 1),
        );

        self::assertSame(2, $result['count_delta']['claim']['delta']);
        self::assertNull($result['count_delta']['merge']['delta']);
        self::assertFalse($result['count_delta']['merge']['present_in_both']);
        self::assertSame(1000, $result['duration_delta_ms']['claim_to_lease']['p50_delta_ms']);
        self::assertSame(50, $result['cost_delta_micros']);
        self::assertSame(1, $result['anomaly_count_delta']);
        self::assertArrayNotHasKey('ratio', $result);
        self::assertArrayNotHasKey('percent', $result);
        self::assertArrayNotHasKey('normalized', $result);
        self::assertArrayNotHasKey('score', $result);
    }

    #[Test]
    public function it_throws_when_window_labels_differ(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasLoopRollingWindowComparator)->compare(
            $this->bucket('1h', '2026-06-24T13:00:00+00:00', '2026-06-24T14:00:00+00:00'),
            $this->bucket('6h', '2026-06-24T07:00:00+00:00', '2026-06-24T13:00:00+00:00'),
        );
    }

    #[Test]
    public function it_throws_when_buckets_are_not_adjacent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasLoopRollingWindowComparator)->compare(
            $this->bucket('1h', '2026-06-24T13:00:00+00:00', '2026-06-24T14:00:00+00:00'),
            $this->bucket('1h', '2026-06-24T11:00:00+00:00', '2026-06-24T12:00:00+00:00'),
        );
    }

    #[Test]
    public function it_is_deterministic_for_identical_inputs(): void
    {
        $comparator = new AtlasLoopRollingWindowComparator;
        $current = $this->bucket('1h', '2026-06-24T13:00:00+00:00', '2026-06-24T14:00:00+00:00', ['claim' => 4]);
        $previous = $this->bucket('1h', '2026-06-24T12:00:00+00:00', '2026-06-24T13:00:00+00:00', ['claim' => 2]);

        $first = $comparator->compare($current, $previous);
        $second = $comparator->compare($current, $previous);

        self::assertSame(json_encode($first), json_encode($second));
    }

    /**
     * @param  array<string,int>  $counts
     * @param  array<string,array{p50:int,p95:int}>  $durations
     * @return array<string,mixed>
     */
    private function bucket(
        string $label,
        string $startIso,
        string $endIso,
        array $counts = [],
        array $durations = [],
        int $costSumMicros = 0,
        int $anomalyCount = 0,
    ): array {
        return [
            'window_label' => $label,
            'window_start_iso' => $startIso,
            'window_end_iso' => $endIso,
            'counts' => $counts,
            'durations_ms' => $durations,
            'cost_sum_micros' => $costSumMicros,
            'anomaly_count' => $anomalyCount,
        ];
    }
}
