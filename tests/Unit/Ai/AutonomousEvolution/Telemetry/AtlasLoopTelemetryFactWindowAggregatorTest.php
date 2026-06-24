<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactWindowAggregator;
use Tests\TestCase;

final class AtlasLoopTelemetryFactWindowAggregatorTest extends TestCase
{
    public function test_aggregate_returns_counts_percentiles_and_is_deterministic_without_forbidden_keys(): void
    {
        $facts = [
            $this->fact('cycle-1', 'claim', '2026-06-24T00:30:00+00:00'),
            $this->fact('cycle-1', 'lease', '2026-06-24T00:30:10+00:00'),
            $this->fact('cycle-1', 'serve', '2026-06-24T00:30:25+00:00'),
            $this->fact('cycle-1', 'report', '2026-06-24T00:31:05+00:00'),
            $this->fact('cycle-1', 'merge', '2026-06-24T00:31:35+00:00'),
            $this->fact('cycle-2', 'claim', '2026-06-24T00:40:00+00:00'),
            $this->fact('cycle-2', 'lease', '2026-06-24T00:40:20+00:00'),
            $this->fact('cycle-2', 'serve', '2026-06-24T00:40:50+00:00'),
            $this->fact('cycle-2', 'report', '2026-06-24T00:41:50+00:00'),
            $this->fact('cycle-2', 'merge', '2026-06-24T00:42:40+00:00'),
            $this->fact('cycle-3', 'claim', '2026-06-24T00:50:00+00:00'),
            $this->fact('cycle-3', 'lease', '2026-06-24T00:50:30+00:00'),
            $this->fact('cycle-4', 'claim', '2026-06-24T00:10:00+00:00'),
            $this->fact('cycle-4', 'lease', '2026-06-24T00:10:05+00:00'),
        ];

        $aggregator = new AtlasLoopTelemetryFactWindowAggregator;
        $first = $aggregator->aggregate($facts, '2026-06-24T01:00:00+00:00', 30);
        $second = $aggregator->aggregate($facts, '2026-06-24T01:00:00+00:00', 30);

        $this->assertSame(
            [
                'claim' => 3,
                'lease' => 3,
                'serve' => 2,
                'report' => 2,
                'merge' => 2,
            ],
            $first['counts']
        );
        $this->assertSame(
            [
                'claim_to_lease' => ['p50' => 20000, 'p95' => 30000, 'n' => 3],
                'lease_to_serve' => ['p50' => 15000, 'p95' => 30000, 'n' => 2],
                'serve_to_report' => ['p50' => 40000, 'p95' => 60000, 'n' => 2],
                'report_to_merge' => ['p50' => 30000, 'p95' => 50000, 'n' => 2],
            ],
            $first['durations_ms']
        );

        $encoded = json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->assertDoesNotMatchRegularExpression('/"[^"]*(score|rank|grade|quality|health)[^"]*"\s*:/i', $encoded);
        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            json_encode($second, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fact(string $cycleId, string $kind, string $occurredAtIso): array
    {
        return [
            'cycle_id' => $cycleId,
            'kind' => $kind,
            'occurred_at_iso' => $occurredAtIso,
            'scope' => 'loop',
            'schema_version' => 'atlas.loop.telemetry.fact.v1',
            'payload' => ['scope' => 'loop'],
        ];
    }
}
