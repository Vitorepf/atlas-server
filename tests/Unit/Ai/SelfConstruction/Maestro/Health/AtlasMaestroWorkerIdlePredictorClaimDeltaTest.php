<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroWorkerIdlePredictor;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

final class AtlasMaestroWorkerIdlePredictorClaimDeltaTest extends TestCase
{
    private function predictor(array $health, array $serving, ?DateTimeImmutable $now = null): AtlasMaestroWorkerIdlePredictor
    {
        $now ??= new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));

        $healthObj = new class($health)
        {
            public function __construct(private array $facts) {}

            public function snapshot(): array
            {
                return $this->facts;
            }
        };
        $sentinelObj = new class($serving)
        {
            public function __construct(private array $facts) {}

            public function status(): array
            {
                return $this->facts;
            }
        };

        return new AtlasMaestroWorkerIdlePredictor($healthObj, $sentinelObj, static fn (): DateTimeImmutable => $now);
    }

    public function test_zero_serve_total_with_claim_delta_estimates_fallback_drain_rate(): void
    {
        $projection = $this->predictor(
            ['claimable_depth' => 10, 'active_leases' => 3],
            ['serve_total' => 0, 'window_elapsed_seconds' => 120, 'completed_dry_run' => 4],
        )->project();

        $this->assertSame('telemetry_blind_spot:zero_serve_with_active_workers_and_claimable_backlog', $projection['reason']);
        $this->assertArrayHasKey('fallback_drain_rate_per_minute', $projection);
        $this->assertEqualsWithDelta(2.0, $projection['fallback_drain_rate_per_minute'], 0.000001);
        $this->assertSame(300, $projection['seconds_until_dry']);
    }

    public function test_zero_serve_total_with_no_claim_delta_preserves_blind_spot_with_null_projection(): void
    {
        $projection = $this->predictor(
            ['claimable_depth' => 10, 'active_leases' => 3],
            ['serve_total' => 0, 'window_elapsed_seconds' => 120],
        )->project();

        $this->assertSame('telemetry_blind_spot:zero_serve_with_active_workers_and_claimable_backlog', $projection['reason']);
        $this->assertArrayNotHasKey('fallback_drain_rate_per_minute', $projection);
        $this->assertNull($projection['seconds_until_dry']);
    }
}
