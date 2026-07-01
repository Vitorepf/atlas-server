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

    /** Age (seconds) at/above which a packet is considered old for value-decay classification — matches the existing fresh/stale split (15-60m bucket onward). */
    private const OLD_THRESHOLD_SECONDS = 900;

    /** value_score at/above this is high-value; below is low-value. */
    private const VALUE_THRESHOLD = 0.5;

    public const RECOMMENDATION_NONE = 'none';

    public const RECOMMENDATION_STALE_LOW_VALUE = 'stale_low_value';

    public const RECOMMENDATION_PRIORITY_RESCUE = 'priority_rescue';

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
        $packetAges = [];
        $classifications = [];

        foreach ($this->queueRepo()->list(['status' => 'claimable']) as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $age = max(0, $now - $this->claimableSinceUnix($packet));
            $ages[] = $age;
            $packetId = (string) ($packet['task_packet_id'] ?? '');
            $packetAges[] = ['age' => $age, 'id' => $packetId];
            $classifications[] = $this->classify($packetId, $age, $packet);
        }

        sort($ages, SORT_NUMERIC);
        usort($packetAges, static fn (array $a, array $b): int => $b['age'] <=> $a['age']);

        $bins = $this->bins($ages);

        // Fresh = first 3 buckets (<1m, 1-5m, 5-15m — age < 900s).
        // Stale = remaining buckets (15-60m and older).
        $freshCount = array_sum(array_column(array_slice($bins, 0, 3), 'count'));
        $staleCount = count($ages) - $freshCount;
        $oldestIds = array_values(array_filter(
            array_column(array_slice($packetAges, 0, 5), 'id'),
            static fn (string $id): bool => $id !== '',
        ));

        return [
            'schema' => self::SCHEMA,
            'total_claimable' => count($ages),
            'bins' => $bins,
            'oldest_seconds' => $ages === [] ? 0 : max($ages),
            'p50_seconds' => $this->nearestRank($ages, 0.50),
            'p95_seconds' => $this->nearestRank($ages, 0.95),
            'fresh_count' => $freshCount,
            'stale_count' => $staleCount,
            'starvation_risk' => $staleCount > 0 && $freshCount === 0,
            'oldest_packet_ids' => $oldestIds,
            'packet_classifications' => $classifications,
            'stale_low_value_ids' => array_values(array_column(
                array_filter($classifications, static fn (array $c): bool => $c['recommended_action'] === self::RECOMMENDATION_STALE_LOW_VALUE),
                'packet_id'
            )),
            'priority_rescue_ids' => array_values(array_column(
                array_filter($classifications, static fn (array $c): bool => $c['recommended_action'] === self::RECOMMENDATION_PRIORITY_RESCUE),
                'packet_id'
            )),
        ];
    }

    /**
     * Classifies a single packet by age + value: old+low-value → stale_low_value (deprioritize),
     * old+high-value → priority_rescue (surface before it dies), otherwise none.
     *
     * @param  array<string,mixed>  $packet
     * @return array{packet_id:string, age_seconds:int, age_bucket:string, value_score:float, value_decay:float, recommended_action:string}
     */
    private function classify(string $packetId, int $age, array $packet): array
    {
        $valueScore = max(0.0, min(1.0, (float) ($packet['value_score'] ?? $packet['expected_value'] ?? 0.0)));
        $valueDecay = round($valueScore * (1.0 / (1.0 + $age / 3600.0)), 4);
        $isOld = $age >= self::OLD_THRESHOLD_SECONDS;

        $recommendedAction = self::RECOMMENDATION_NONE;
        if ($isOld && $valueScore < self::VALUE_THRESHOLD) {
            $recommendedAction = self::RECOMMENDATION_STALE_LOW_VALUE;
        } elseif ($isOld && $valueScore >= self::VALUE_THRESHOLD) {
            $recommendedAction = self::RECOMMENDATION_PRIORITY_RESCUE;
        }

        return [
            'packet_id' => $packetId,
            'age_seconds' => $age,
            'age_bucket' => $this->ageBucketLabel($age),
            'value_score' => $valueScore,
            'value_decay' => $valueDecay,
            'recommended_action' => $recommendedAction,
        ];
    }

    private function ageBucketLabel(int $age): string
    {
        foreach (self::BUCKETS as $bucket) {
            if ($age < $bucket['lower_seconds']) {
                continue;
            }
            if ($bucket['upper_seconds'] !== null && $age >= $bucket['upper_seconds']) {
                continue;
            }

            return $bucket['label'];
        }

        return '>24h';
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
