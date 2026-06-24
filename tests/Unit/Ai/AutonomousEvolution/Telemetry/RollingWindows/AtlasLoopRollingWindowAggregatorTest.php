<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry\RollingWindows;

use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowAggregator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasLoopRollingWindowAggregatorTest extends TestCase
{
    #[Test]
    public function it_is_deterministic_for_the_same_input_and_now_iso(): void
    {
        $aggregator = new AtlasLoopRollingWindowAggregator;
        $facts = [
            $this->fact('claim', 'cycle-1', '2026-06-24T13:10:00+00:00'),
            $this->fact('lease', 'cycle-1', '2026-06-24T13:11:00+00:00'),
        ];

        $first = $aggregator->aggregate($facts, '2026-06-24T13:50:00+00:00');
        $second = $aggregator->aggregate($facts, '2026-06-24T13:50:00+00:00');

        self::assertSame(json_encode($first), json_encode($second));
    }

    #[Test]
    public function it_emits_facts_only_counts_durations_cost_and_anomaly_without_scores(): void
    {
        $aggregator = new AtlasLoopRollingWindowAggregator;

        $result = $aggregator->aggregate([
            $this->fact('claim', 'cycle-1', '2026-06-24T13:10:00+00:00', ['cost_micros' => 50]),
            $this->fact('lease', 'cycle-1', '2026-06-24T13:11:00+00:00', ['anomaly_count' => 2]),
            $this->fact('unknown', 'cycle-1', '2026-06-24T13:12:00+00:00'),
        ], '2026-06-24T13:50:00+00:00');

        $bucket = $result['buckets'][0];
        self::assertSame(1, $bucket['counts']['claim']);
        self::assertSame(1, $bucket['counts']['lease']);
        self::assertSame(1, $bucket['counts']['telemetry-cost']);
        self::assertSame(1, $bucket['counts']['anomaly']);
        self::assertSame(50, $bucket['cost_sum_micros']);
        self::assertSame(2, $bucket['anomaly_count']);
        self::assertArrayNotHasKey('score', $bucket);
        self::assertArrayNotHasKey('rank', $bucket);
        self::assertArrayNotHasKey('normalized', $bucket);
    }

    #[Test]
    public function it_floor_aligns_window_boundaries_in_utc(): void
    {
        $aggregator = new AtlasLoopRollingWindowAggregator;
        $result = $aggregator->aggregate([
            $this->fact('claim', 'cycle-1', '2026-06-24T13:42:00+00:00'),
        ], '2026-06-24T13:50:00+00:00');

        self::assertSame('2026-06-24T13:00:00+00:00', $result['buckets'][0]['window_start_iso']);
        self::assertSame('2026-06-24T14:00:00+00:00', $result['buckets'][0]['window_end_iso']);
        self::assertSame('2026-06-24T12:00:00+00:00', $result['buckets'][1]['window_start_iso']);
        self::assertSame('2026-06-24T18:00:00+00:00', $result['buckets'][1]['window_end_iso']);
        self::assertSame('2026-06-24T00:00:00+00:00', $result['buckets'][2]['window_start_iso']);
        self::assertSame('2026-06-25T00:00:00+00:00', $result['buckets'][2]['window_end_iso']);
    }

    #[Test]
    public function it_excludes_facts_outside_the_rolling_interval_and_skips_unknown_kinds(): void
    {
        $aggregator = new AtlasLoopRollingWindowAggregator;

        $result = $aggregator->aggregate([
            $this->fact('claim', 'cycle-1', '2026-06-24T12:49:59+00:00'),
            $this->fact('claim', 'cycle-1', '2026-06-24T12:50:00+00:00'),
            $this->fact('unknown', 'cycle-1', '2026-06-24T13:10:00+00:00'),
        ], '2026-06-24T13:50:00+00:00');

        self::assertSame(1, $result['buckets'][0]['counts']['claim']);
        self::assertSame(0, $result['buckets'][0]['counts']['merge']);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function fact(string $kind, string $cycleId, string $occurredAtIso, array $payload = []): array
    {
        return [
            'kind' => $kind,
            'cycle_id' => $cycleId,
            'occurred_at_iso' => $occurredAtIso,
            'payload' => $payload,
        ];
    }
}
