<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class AtlasMaestroLeaseLifetimeHistogram
{
    public const SCHEMA = 'atlas.maestro.health.lease_lifetime_histogram.v1';

    /** @var list<array{label:string,lower_seconds:int,upper_seconds:int|null}> */
    private const BUCKETS = [
        ['label' => '<30s', 'lower_seconds' => 0, 'upper_seconds' => 30],
        ['label' => '30s-2m', 'lower_seconds' => 30, 'upper_seconds' => 120],
        ['label' => '2-10m', 'lower_seconds' => 120, 'upper_seconds' => 600],
        ['label' => '10-60m', 'lower_seconds' => 600, 'upper_seconds' => 3600],
        ['label' => '1-4h', 'lower_seconds' => 3600, 'upper_seconds' => 14400],
        ['label' => '>4h', 'lower_seconds' => 14400, 'upper_seconds' => null],
    ];

    private Closure $clock;

    /**
     * @param  object|null  $leases Object exposing activeLeases(): list<array<string,mixed>>.
     */
    public function __construct(
        private readonly ?object $leases = null,
        ?callable $clock = null,
        private readonly int $suspectedStuckThresholdSeconds = 3600,
    ) {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * @return array{
     *     schema:string,
     *     total_active:int,
     *     bins:list<array{label:string,lower_seconds:int,upper_seconds:int|null,count:int}>,
     *     oldest_seconds:int,
     *     p50_seconds:int,
     *     p95_seconds:int,
     *     suspected_stuck_count:int
     * }
     */
    public function histogram(): array
    {
        $now = ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $lifetimes = [];

        foreach ($this->leaseRepo()->activeLeases() as $lease) {
            if (! is_array($lease)) {
                continue;
            }

            $lifetimes[] = max(0, $now - $this->leasedAtUnix($lease));
        }

        sort($lifetimes, SORT_NUMERIC);

        return [
            'schema' => self::SCHEMA,
            'total_active' => count($lifetimes),
            'bins' => $this->bins($lifetimes),
            'oldest_seconds' => $lifetimes === [] ? 0 : max($lifetimes),
            'p50_seconds' => $this->nearestRank($lifetimes, 0.50),
            'p95_seconds' => $this->nearestRank($lifetimes, 0.95),
            'suspected_stuck_count' => count(array_filter(
                $lifetimes,
                fn (int $seconds): bool => $seconds > $this->suspectedStuckThresholdSeconds,
            )),
        ];
    }

    private function leaseRepo(): object
    {
        return $this->leases ?? new AgentControlPlaneClaimLeaseRepository(AtlasTaskServingStack::disk());
    }

    /**
     * @param  array<string,mixed>  $lease
     */
    private function leasedAtUnix(array $lease): int
    {
        foreach (['leased_at_unix', 'acquired_at_unix', 'claimed_at_unix'] as $key) {
            if (isset($lease[$key]) && is_numeric($lease[$key])) {
                return (int) $lease[$key];
            }
        }

        foreach (['leased_at', 'acquired_at', 'claimed_at'] as $key) {
            if (isset($lease[$key]) && is_string($lease[$key]) && trim($lease[$key]) !== '') {
                return (new DateTimeImmutable($lease[$key]))->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
            }
        }

        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    }

    /**
     * @param  list<int>  $lifetimes
     * @return list<array{label:string,lower_seconds:int,upper_seconds:int|null,count:int}>
     */
    private function bins(array $lifetimes): array
    {
        $bins = [];
        foreach (self::BUCKETS as $bucket) {
            $count = 0;
            foreach ($lifetimes as $seconds) {
                if ($seconds < $bucket['lower_seconds']) {
                    continue;
                }
                if ($bucket['upper_seconds'] !== null && $seconds >= $bucket['upper_seconds']) {
                    continue;
                }
                $count++;
            }

            $bins[] = [
                'label' => $bucket['label'],
                'lower_seconds' => $bucket['lower_seconds'],
                'upper_seconds' => $bucket['upper_seconds'],
                'count' => $count,
            ];
        }

        return $bins;
    }

    /**
     * @param  list<int>  $values
     */
    private function nearestRank(array $values, float $percentile): int
    {
        if ($values === []) {
            return 0;
        }

        $rank = (int) ceil($percentile * count($values));
        $index = max(0, min(count($values) - 1, $rank - 1));

        return $values[$index];
    }
}
