<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroReplenishUrgencyClassifier;
use Tests\TestCase;

/**
 * Pins the claimable-depth-per-active-worker starvation signal: claimable_depth alone can look
 * healthy while active workers are about to starve because supply per worker is near one.
 */
final class AtlasMaestroReplenishUrgencyClassifierWorkerRatioTest extends TestCase
{
    public function test_near_one_per_active_worker_is_high_with_originate_even_without_dry_eta_or_stale_age(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 30, 'p95_seconds' => 30],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: [
                'claimable_depth' => 10,
                'serve_rate_per_minute' => 5.0,
                'seconds_until_dry' => null,
                'active_claimed_workers' => 6,
                'poison_pressure' => 0,
            ],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 3600,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertSame('originate', $result['next_action']);
        $this->assertContains('claimable_depth_near_one_per_active_worker', $result['reasons']);
        $this->assertSame(10, $result['inputs']['claimable_depth']);
        $this->assertSame(6, $result['inputs']['active_claimed_workers']);
        $this->assertNull($result['inputs']['seconds_until_dry']);
    }

    public function test_claimable_depth_comfortably_above_double_active_workers_is_low_and_waits(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 30, 'p95_seconds' => 30],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: [
                'claimable_depth' => 13,
                'serve_rate_per_minute' => 5.0,
                'seconds_until_dry' => null,
                'active_claimed_workers' => 6,
                'poison_pressure' => 0,
            ],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 3600,
        )->classify();

        $this->assertSame('LOW', $result['urgency']);
        $this->assertSame('wait', $result['next_action']);
        $this->assertNotContains('claimable_depth_near_one_per_active_worker', $result['reasons']);
    }

    public function test_claimable_depth_at_exactly_double_active_workers_is_high_not_wait(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 30, 'p95_seconds' => 30],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: [
                'claimable_depth' => 12,
                'serve_rate_per_minute' => 5.0,
                'seconds_until_dry' => null,
                'active_claimed_workers' => 6,
                'poison_pressure' => 0,
            ],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 3600,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertNotSame('wait', $result['next_action']);
        $this->assertContains('claimable_depth_near_one_per_active_worker', $result['reasons']);
        $this->assertContains($result['replenish_action'], ['replenish_soon', 'replenish_urgently']);
    }

    public function test_zero_active_workers_does_not_trigger_ratio_signal(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 30, 'p95_seconds' => 30],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: [
                'claimable_depth' => 1,
                'serve_rate_per_minute' => 1.0,
                'seconds_until_dry' => null,
                'active_claimed_workers' => 0,
                'poison_pressure' => 0,
            ],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 3600,
        )->classify();

        $this->assertNotContains('claimable_depth_near_one_per_active_worker', $result['reasons']);
    }

    public function test_ratio_signal_is_deterministic(): void
    {
        $classifier = $this->classifier(
            queue: ['oldest_seconds' => 30, 'p95_seconds' => 30],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: [
                'claimable_depth' => 10,
                'serve_rate_per_minute' => 5.0,
                'seconds_until_dry' => null,
                'active_claimed_workers' => 6,
                'poison_pressure' => 0,
            ],
        );

        $this->assertSame($classifier->classify(), $classifier->classify());
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
