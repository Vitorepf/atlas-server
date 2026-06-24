<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroLeaseLifetimeHistogram;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

final class AtlasMaestroLeaseLifetimeHistogramTest extends TestCase
{
    public function test_histogram_bins_percentiles_stuck_threshold_and_read_only_repository_use(): void
    {
        $now = new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));
        $leases = [
            $this->leaseHeldFor($now, 10),
            $this->leaseHeldFor($now, 45),
            $this->leaseHeldFor($now, 180),
            $this->leaseHeldFor($now, 900),
            $this->leaseHeldFor($now, 3600),
            $this->leaseHeldFor($now, 7200),
            $this->leaseHeldFor($now, 20000),
        ];
        $repo = new class($leases)
        {
            public int $activeLeasesCalls = 0;

            public int $releaseCalls = 0;

            public int $recoverCalls = 0;

            /** @param list<array<string,mixed>> $leases */
            public function __construct(private readonly array $leases) {}

            /** @return list<array<string,mixed>> */
            public function activeLeases(): array
            {
                $this->activeLeasesCalls++;

                return $this->leases;
            }

            public function release(): void
            {
                $this->releaseCalls++;
            }

            public function recover(): void
            {
                $this->recoverCalls++;
            }

            /** @return array<string,mixed> */
            public function state(): array
            {
                return [
                    'leases' => $this->leases,
                    'active_leases_calls' => $this->activeLeasesCalls,
                    'release_calls' => $this->releaseCalls,
                    'recover_calls' => $this->recoverCalls,
                ];
            }
        };

        $before = $repo->state();
        $histogram = (new AtlasMaestroLeaseLifetimeHistogram(
            $repo,
            static fn (): DateTimeImmutable => $now,
            suspectedStuckThresholdSeconds: 3600,
        ))->histogram();
        $after = $repo->state();

        $this->assertSame('atlas.maestro.health.lease_lifetime_histogram.v1', $histogram['schema']);
        $this->assertSame(7, $histogram['total_active']);
        $this->assertSame([
            ['label' => '<30s', 'lower_seconds' => 0, 'upper_seconds' => 30, 'count' => 1],
            ['label' => '30s-2m', 'lower_seconds' => 30, 'upper_seconds' => 120, 'count' => 1],
            ['label' => '2-10m', 'lower_seconds' => 120, 'upper_seconds' => 600, 'count' => 1],
            ['label' => '10-60m', 'lower_seconds' => 600, 'upper_seconds' => 3600, 'count' => 1],
            ['label' => '1-4h', 'lower_seconds' => 3600, 'upper_seconds' => 14400, 'count' => 2],
            ['label' => '>4h', 'lower_seconds' => 14400, 'upper_seconds' => null, 'count' => 1],
        ], $histogram['bins']);
        $this->assertSame(7, array_sum(array_column($histogram['bins'], 'count')));
        $this->assertSame(20000, $histogram['oldest_seconds']);
        $this->assertSame(900, $histogram['p50_seconds']);
        $this->assertSame(20000, $histogram['p95_seconds']);
        $this->assertSame(2, $histogram['suspected_stuck_count']);
        $this->assertSame(0, $before['active_leases_calls']);
        $this->assertSame(1, $after['active_leases_calls']);
        $this->assertSame(0, $after['release_calls']);
        $this->assertSame(0, $after['recover_calls']);
        $this->assertSame($before['leases'], $after['leases']);
    }

    public function test_empty_active_lease_set_returns_zeroed_facts(): void
    {
        $repo = new class
        {
            /** @return list<array<string,mixed>> */
            public function activeLeases(): array
            {
                return [];
            }
        };

        $histogram = (new AtlasMaestroLeaseLifetimeHistogram($repo))->histogram();

        $this->assertSame(0, $histogram['total_active']);
        $this->assertSame(0, array_sum(array_column($histogram['bins'], 'count')));
        $this->assertSame(0, $histogram['oldest_seconds']);
        $this->assertSame(0, $histogram['p50_seconds']);
        $this->assertSame(0, $histogram['p95_seconds']);
        $this->assertSame(0, $histogram['suspected_stuck_count']);
    }

    /**
     * @return array<string,mixed>
     */
    private function leaseHeldFor(DateTimeImmutable $now, int $seconds): array
    {
        $leasedAt = $now->sub(new \DateInterval('PT'.$seconds.'S'));

        return [
            'lease_id' => 'lease_'.$seconds,
            'lease_status' => 'active',
            'acquired_at' => $leasedAt->format(DATE_ATOM),
            'acquired_at_unix' => $leasedAt->getTimestamp(),
        ];
    }
}
