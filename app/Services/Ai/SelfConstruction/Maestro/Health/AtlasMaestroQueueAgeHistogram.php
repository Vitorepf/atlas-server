<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class AtlasMaestroQueueAgeHistogram
{
    public const SCHEMA = 'atlas.maestro.health.queue_age_histogram.v1';

    /** @var list<array{label:string,lower_seconds:int,upper_seconds:int|null}> */
    private const BUCKETS = [
        ['label' => '<1m', 'lower_seconds' => 0, 'upper_seconds' => 60],
        ['label' => '1-5m', 'lower_seconds' => 60, 'upper_seconds' => 300],
        ['label' => '5-15m', 'lower_seconds' => 300, 'upper_seconds' => 900],
        ['label' => '15-60m', 'lower_seconds' => 900, 'upper_seconds' => 3600],
        ['label' => '1-6h', 'lower_seconds' => 3600, 'upper_seconds' => 21600],
        ['label' => '6-24h', 'lower_seconds' => 21600, 'upper_seconds' => 86400],
        ['label' => '>24h', 'lower_seconds' => 86400, 'upper_seconds' => null],
    ];

    private Closure $clock;

    /**
     * @param  object|null  $queue Object exposing list(array $filters): list<array<string,mixed>>.
     */
    public function __construct(
        private readonly ?object $queue = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * @return array{
     *     schema:string,
     *     total_claimable:int,
     *     bins:list<array{label:string,lower_seconds:int,upper_seconds:int|null,count:int}>,
     *     oldest_seconds:int,
     *     p50_seconds:int,
     *     p95_seconds:int
     * }
     */
    public function histogram(): array
    {
        $now = ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $ages = [];

        foreach ($this->queueRepo()->list(['status' => 'claimable']) as $packet) {
            if (! is_array($packet)) {
                continue;
            }

            $ages[] = max(0, $now - $this->claimableSinceUnix($packet));
        }

        sort($ages, SORT_NUMERIC);

        return [
            'schema' => self::SCHEMA,
            'total_claimable' => count($ages),
            'bins' => $this->bins($ages),
            'oldest_seconds' => $ages === [] ? 0 : max($ages),
            'p50_seconds' => $this->nearestRank($ages, 0.50),
            'p95_seconds' => $this->nearestRank($ages, 0.95),
        ];
    }

    private function queueRepo(): object
    {
        return $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository(AtlasTaskServingStack::disk());
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function claimableSinceUnix(array $packet): int
    {
        foreach (['claimable_since_unix', 'created_at_unix', 'enqueued_at_unix', 'updated_at_unix'] as $key) {
            if (isset($packet[$key]) && is_numeric($packet[$key])) {
                return (int) $packet[$key];
            }
        }

        foreach (['claimable_since', 'created_at', 'enqueued_at', 'updated_at'] as $key) {
            if (isset($packet[$key]) && is_string($packet[$key]) && trim($packet[$key]) !== '') {
                return (new DateTimeImmutable($packet[$key]))->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
            }
        }

        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    }

    /**
     * @param  list<int>  $ages
     * @return list<array{label:string,lower_seconds:int,upper_seconds:int|null,count:int}>
     */
    private function bins(array $ages): array
    {
        $bins = [];
        foreach (self::BUCKETS as $bucket) {
            $count = 0;
            foreach ($ages as $seconds) {
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
