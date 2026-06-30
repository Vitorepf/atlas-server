<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryStarvationDetector;
use Tests\TestCase;

final class AtlasLoopTelemetryStarvationDetectorTest extends TestCase
{
    public function test_empty_stream_is_not_starved(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->detect([], '2026-06-24T01:00:00+00:00', 30);

        $this->assertSame(
            [
                'starved',
                'window',
                'counts',
                'evidence',
            ],
            array_keys($result)
        );
        $this->assertFalse($result['starved']);
        $this->assertSame(['claim' => 0, 'lease' => 0, 'serve' => 0], $result['counts']);
        $this->assertSame(['paired_claim_to_serve' => 0], $result['evidence']);
    }

    public function test_claims_without_serve_in_window_are_starved(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->detect([
            $this->fact('cycle-1', 'claim', '2026-06-24T00:40:00+00:00'),
            $this->fact('cycle-1', 'lease', '2026-06-24T00:40:20+00:00'),
            $this->fact('cycle-2', 'claim', '2026-06-24T00:50:00+00:00'),
        ], '2026-06-24T01:00:00+00:00', 30);

        $this->assertTrue($result['starved']);
        $this->assertSame(['claim' => 2, 'lease' => 1, 'serve' => 0], $result['counts']);
        $this->assertSame(['paired_claim_to_serve' => 0], $result['evidence']);
    }

    public function test_claims_with_a_paired_serve_are_not_starved_and_outside_window_is_ignored(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->detect([
            $this->fact('old-cycle', 'claim', '2026-06-24T00:10:00+00:00'),
            $this->fact('old-cycle', 'serve', '2026-06-24T00:10:10+00:00'),
            $this->fact('cycle-1', 'claim', '2026-06-24T00:40:00+00:00'),
            $this->fact('cycle-1', 'lease', '2026-06-24T00:40:15+00:00'),
            $this->fact('cycle-1', 'serve', '2026-06-24T00:40:30+00:00'),
        ], '2026-06-24T01:00:00+00:00', 30);

        $this->assertFalse($result['starved']);
        $this->assertSame(['claim' => 1, 'lease' => 1, 'serve' => 1], $result['counts']);
        $this->assertSame(['paired_claim_to_serve' => 1], $result['evidence']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/"[^"]*(score|rank|grade|quality|severity|recommendation)[^"]*"\s*:/i', $encoded);
    }

    public function test_cross_window_claim_with_in_window_serve_is_not_starved(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->detect([
            $this->fact('cycle-x', 'claim', '2026-06-24T00:20:00+00:00'),
            $this->fact('cycle-x', 'serve', '2026-06-24T00:45:00+00:00'),
        ], '2026-06-24T01:00:00+00:00', 30);

        $this->assertFalse($result['starved'], 'in-window serve must prevent starvation even when its claim is outside the window');
        $this->assertSame(0, $result['counts']['claim']);
        $this->assertSame(1, $result['counts']['serve']);
        $this->assertSame(0, $result['evidence']['paired_claim_to_serve']);
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

    public function test_worker_floor_starvation_detected_when_claimable_per_active_worker_at_floor(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->evaluateWorkerFloor([
            'servable_now' => 1,
            'active_leases' => 3,
            'claimable_per_active_worker' => 2,
            'no_claimable_task_outcome_count' => 0,
        ]);

        $this->assertSame('worker_floor_starvation', $result['recommendation']);
        $this->assertTrue($result['worker_floor_breached']);
        $this->assertSame(2, $result['evidence']['claimable_per_active_worker']);
    }

    public function test_worker_floor_starvation_detected_from_recent_no_claimable_task_outcome(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->evaluateWorkerFloor([
            'servable_now' => 5,
            'active_leases' => 4,
            'claimable_per_active_worker' => 10,
            'no_claimable_task_outcome_count' => 2,
        ]);

        $this->assertSame('worker_floor_starvation', $result['recommendation']);
        $this->assertTrue($result['worker_floor_breached']);
    }

    public function test_idle_system_with_no_active_workers_and_no_recent_outcomes_is_healthy_idle(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->evaluateWorkerFloor([
            'servable_now' => 0,
            'active_leases' => 0,
            'no_claimable_task_outcome_count' => 0,
        ]);

        $this->assertSame('healthy_idle', $result['recommendation']);
        $this->assertFalse($result['worker_floor_breached']);
    }

    public function test_comfortable_buffer_with_active_workers_and_no_recent_outcomes_is_healthy(): void
    {
        $result = (new AtlasLoopTelemetryStarvationDetector)->evaluateWorkerFloor([
            'servable_now' => 20,
            'active_leases' => 3,
            'claimable_per_active_worker' => 10,
            'no_claimable_task_outcome_count' => 0,
        ]);

        $this->assertSame('healthy', $result['recommendation']);
        $this->assertFalse($result['worker_floor_breached']);
    }
}
