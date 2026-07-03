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

    /** Leases expiring within this many seconds are considered near-expiry. */
    public const NEAR_EXPIRY_THRESHOLD_SECONDS = 300;

    /** Workers holding at least this many leases appear in worker_hotspots. */
    public const HOTSPOT_MIN_LEASES = 2;

    /** Leases held at least this long are reported in the long-tail list. */
    public const LONG_TAIL_THRESHOLD_SECONDS = 3600;

    /** Fraction of active leases showing a claim-mismatch signal above which ghost_lease_risk is 'high'. */
    private const GHOST_RISK_HIGH_RATIO = 0.2;

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
        $nearExpiry = 0;
        $expired = 0;
        $workerCounts = [];
        $longTailLeases = [];
        $ghostSignalCount = 0;
        $safeReapRecommendation = [];

        foreach ($this->leaseRepo()->activeLeases() as $lease) {
            if (! is_array($lease)) {
                continue;
            }

            $leaseId = (string) ($lease['lease_id'] ?? '');
            $lifetimeSeconds = max(0, $now - $this->leasedAtUnix($lease));
            $lifetimes[] = $lifetimeSeconds;

            $exp = $this->expiresAtUnix($lease, $now);
            $isExpired = false;
            if ($exp !== null) {
                if ($exp <= $now) {
                    $expired++;
                    $isExpired = true;
                } elseif ($exp <= $now + self::NEAR_EXPIRY_THRESHOLD_SECONDS) {
                    $nearExpiry++;
                }
            }

            $wid = $this->workerId($lease);
            if ($wid !== null) {
                $workerCounts[$wid] = ($workerCounts[$wid] ?? 0) + 1;
            }

            if ($lifetimeSeconds >= self::LONG_TAIL_THRESHOLD_SECONDS) {
                $longTailLeases[] = ['lease_id' => $leaseId, 'lifetime_seconds' => $lifetimeSeconds];
            }

            $isGhostSignal = $this->hasClaimMismatchSignal($lease);
            if ($isGhostSignal) {
                $ghostSignalCount++;
            }

            if ($isExpired) {
                $safeReapRecommendation[] = ['lease_id' => $leaseId, 'reason' => 'expired'];
            } elseif ($isGhostSignal) {
                $safeReapRecommendation[] = ['lease_id' => $leaseId, 'reason' => 'claim_mismatch_ghost_signal'];
            }
        }

        sort($lifetimes, SORT_NUMERIC);

        $totalActive = count($lifetimes);
        $ghostRatio = $totalActive > 0 ? $ghostSignalCount / $totalActive : 0.0;
        $ghostLeaseRisk = match (true) {
            $ghostSignalCount === 0 => 'none',
            $ghostRatio >= self::GHOST_RISK_HIGH_RATIO => 'high',
            default => 'low',
        };

        $hotspots = [];
        foreach ($workerCounts as $wid => $cnt) {
            $hotspots[] = ['worker_id' => $wid, 'lease_count' => $cnt];
        }
        usort($hotspots, fn (array $a, array $b): int => $a['lease_count'] !== $b['lease_count']
            ? $b['lease_count'] <=> $a['lease_count']
            : strcmp($a['worker_id'], $b['worker_id']));
        $hotspots = array_values(array_filter($hotspots, fn (array $h): bool => $h['lease_count'] >= self::HOTSPOT_MIN_LEASES));

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
            'near_expiry_count' => $nearExpiry,
            'expired_count' => $expired,
            'worker_hotspots' => $hotspots,
            'long_tail_leases' => $longTailLeases,
            'long_tail_count' => count($longTailLeases),
            'ghost_lease_risk' => $ghostLeaseRisk,
            'ghost_signal_count' => $ghostSignalCount,
            'safe_reap_recommendation' => $safeReapRecommendation,
        ];
    }

    /**
     * A claim-mismatch signal: the lease explicitly flags a mismatch, or it declares both a
     * claimed owner and an expected owner that disagree — either way the lease may be a ghost
     * (held by a worker that no longer matches who actually claimed it).
     *
     * @param  array<string,mixed>  $lease
     */
    private function hasClaimMismatchSignal(array $lease): bool
    {
        if ((bool) ($lease['claim_mismatch'] ?? false)) {
            return true;
        }

        $claimedBy = $lease['claimed_by'] ?? null;
        $expectedOwner = $lease['expected_owner'] ?? null;

        return is_string($claimedBy) && is_string($expectedOwner)
            && $claimedBy !== '' && $expectedOwner !== '' && $claimedBy !== $expectedOwner;
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
                try {
                    return (new DateTimeImmutable($lease[$key]))->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
                } catch (\Throwable) {
                    // Malformed date — skip this key and try the next
                }
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
     * @param  array<string,mixed>  $lease
     */
    private function expiresAtUnix(array $lease, int $now): ?int
    {
        if (isset($lease['expires_at_unix']) && is_numeric($lease['expires_at_unix'])) {
            return (int) $lease['expires_at_unix'];
        }
        if (isset($lease['expires_at']) && is_string($lease['expires_at']) && trim($lease['expires_at']) !== '') {
            try {
                return (new DateTimeImmutable($lease['expires_at']))->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
            } catch (\Throwable) {
                // Malformed date — skip
            }
        }
        if (isset($lease['ttl_seconds']) && is_numeric($lease['ttl_seconds'])) {
            return $this->leasedAtUnix($lease) + (int) $lease['ttl_seconds'];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $lease
     */
    private function workerId(array $lease): ?string
    {
        foreach (['client_id', 'worker_id', 'claimed_by', 'locked_by'] as $key) {
            if (isset($lease[$key]) && is_string($lease[$key]) && trim($lease[$key]) !== '') {
                return $lease[$key];
            }
        }

        return null;
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
