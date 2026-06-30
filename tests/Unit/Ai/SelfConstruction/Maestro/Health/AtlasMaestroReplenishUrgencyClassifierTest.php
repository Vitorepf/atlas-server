<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroReplenishUrgencyClassifier;
use Tests\TestCase;

final class AtlasMaestroReplenishUrgencyClassifierTest extends TestCase
{
    public function test_empty_queue_is_high_with_queue_dry_reason(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 0, 'p95_seconds' => 0],
            lease: ['p95_seconds' => 0, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 0, 'serve_rate_per_minute' => 5.0, 'seconds_until_dry' => 900],
        )->classify();

        $this->assertSame('atlas.maestro.health.replenish_urgency.v1', $result['schema']);
        $this->assertSame('HIGH', $result['urgency']);
        $this->assertSame(['queue_dry'], $result['reasons']);
        $this->assertSame(0, $result['inputs']['claimable_depth']);
    }

    public function test_short_seconds_until_dry_is_high(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 20, 'p95_seconds' => 20],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 3, 'serve_rate_per_minute' => 3.0, 'seconds_until_dry' => 59],
            thresholdHighSeconds: 60,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertSame(['seconds_until_dry_below_threshold_high'], $result['reasons']);
        $this->assertSame(59, $result['inputs']['seconds_until_dry']);
        $this->assertSame(60, $result['inputs']['threshold_high_seconds']);
    }

    public function test_stale_p95_claimable_age_is_mid(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 1200, 'p95_seconds' => 900],
            lease: ['p95_seconds' => 30, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 9, 'serve_rate_per_minute' => 1.0, 'seconds_until_dry' => 2000],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
        )->classify();

        $this->assertSame('MID', $result['urgency']);
        $this->assertSame(['p95_claimable_age_above_threshold_stale'], $result['reasons']);
        $this->assertSame(1200, $result['inputs']['oldest_claimable_seconds']);
        $this->assertSame(30, $result['inputs']['p95_lease_lifetime_seconds']);
    }

    public function test_healthy_state_is_low_and_deterministic(): void
    {
        $classifier = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 2000],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
        );

        $first = $classifier->classify();
        $second = $classifier->classify();

        $this->assertSame('LOW', $first['urgency']);
        $this->assertSame(['no_replenish_pressure'], $first['reasons']);
        $this->assertSame($first, $second);
    }

    public function test_suspected_stuck_leases_above_zero_adds_mid_reason(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 1],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 2000, 'active_claimed_workers' => 0, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdHighStuckLeases: 3,
        )->classify();

        $this->assertSame('MID', $result['urgency']);
        $this->assertContains('suspected_stuck_leases_threaten_throughput', $result['reasons']);
        $this->assertSame(1, $result['inputs']['suspected_stuck_leases']);
    }

    public function test_high_stuck_leases_raise_urgency_to_high(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 3],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 2000, 'active_claimed_workers' => 0, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdHighStuckLeases: 3,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertContains('high_stuck_lease_threat', $result['reasons']);
    }

    public function test_low_claimable_depth_with_active_workers_is_high_before_queue_dry(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 50, 'p95_seconds' => 50],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 2, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 9000, 'active_claimed_workers' => 3, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
            thresholdMinActiveWorkers: 2,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertContains('low_claimable_depth_with_active_worker_pressure', $result['reasons']);
    }

    public function test_healthy_deep_queue_with_active_workers_stays_low(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 9000, 'active_claimed_workers' => 5, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
            thresholdMinActiveWorkers: 2,
        )->classify();

        $this->assertSame('LOW', $result['urgency']);
        $this->assertContains('no_replenish_pressure', $result['reasons']);
    }

    /**
     * @param  array<string,mixed>  $queue
     * @param  array<string,mixed>  $lease
     * @param  array<string,mixed>  $idle
     */
    private function classifier(
        array $queue,
        array $lease,
        array $idle,
        int $thresholdHighSeconds = 300,
        int $thresholdMidSeconds = 1800,
        int $thresholdStaleClaimableAgeSeconds = 3600,
        int $thresholdLowClaimableDepth = 5,
        int $thresholdMinActiveWorkers = 2,
        int $thresholdHighStuckLeases = 3,
    ): AtlasMaestroReplenishUrgencyClassifier {
        return new AtlasMaestroReplenishUrgencyClassifier(
            new class($queue)
            {
                public function __construct(private readonly array $facts) {}

                /** @return array<string,mixed> */
                public function histogram(): array
                {
                    return $this->facts;
                }
            },
            new class($lease)
            {
                public function __construct(private readonly array $facts) {}

                /** @return array<string,mixed> */
                public function histogram(): array
                {
                    return $this->facts;
                }
            },
            new class($idle)
            {
                public function __construct(private readonly array $facts) {}

                /** @return array<string,mixed> */
                public function project(): array
                {
                    return $this->facts;
                }
            },
            $thresholdHighSeconds,
            $thresholdMidSeconds,
            $thresholdStaleClaimableAgeSeconds,
            $thresholdLowClaimableDepth,
            $thresholdMinActiveWorkers,
            $thresholdHighStuckLeases,
        );
    }
}
