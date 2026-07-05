<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Decay;

use Closure;

/**
 * Provider-free FACT reporter — emits raw packet-age observations from the
 * AgentControlPlaneTaskPacketQueueRepository for every held packet.
 *
 * Honors loop-master OFF (byte-identical empty list).
 * Reader-only: no writes, no provider spend. Raw FACTS only.
 */
final class AtlasMaestroPacketAgeFactReporter
{
    public const SCHEMA = 'atlas.maestro.packet_age_fact.v1';

    public const VALUE_CLASS_HIGH = 'high';

    public const VALUE_CLASS_LOW = 'low';

    public const FRESHNESS_WORKER_READY = 'worker_ready';

    public const FRESHNESS_STALE_LOW_VALUE = 'stale_low_value';

    public const FRESHNESS_RESCUE_CANDIDATE = 'rescue_candidate';

    /** A packet's expected_value at or above this threshold is classed high-value. */
    private const HIGH_VALUE_THRESHOLD = 5.0;

    /** @var Closure():array<int,array<string,mixed>> */
    private Closure $packetSource;

    /** @var Closure():string */
    private Closure $now;

    /** @var Closure():bool */
    private Closure $masterEnabled;

    /**
     * @param  callable():array<int,array<string,mixed>>  $packetSource
     * @param  callable():string|null  $nowIso
     * @param  callable():bool|null  $masterEnabled
     */
    public function __construct(
        callable $packetSource,
        ?callable $nowIso = null,
        ?callable $masterEnabled = null,
    ) {
        $this->packetSource = Closure::fromCallable($packetSource);
        $this->now = Closure::fromCallable($nowIso ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'));
        $this->masterEnabled = Closure::fromCallable(
            $masterEnabled ?? static fn (): bool => (bool) env('ATLAS_LOOP_MASTER_ENABLED', false),
        );
    }

    /**
     * @return list<array{task_packet_id:string, enqueued_at:string, observed_at:string, time_in_queue_seconds:int, queue_status:string}>
     */
    public function report(): array
    {
        if (! ($this->masterEnabled)()) {
            return [];
        }

        $observedAt = (string) ($this->now)();
        $observedTs = $this->isoToTs($observedAt);

        $packets = (array) ($this->packetSource)();
        $facts = [];
        foreach ($packets as $p) {
            $enqueued = (string) ($p['enqueued_at'] ?? '');
            $ts = $this->isoToTs($enqueued);
            $seconds = ($enqueued !== '' && $ts > 0) ? max(0, $observedTs - $ts) : 0;
            $bucket = $this->ageBucket($seconds);
            $valueClass = $this->valueClass($p);
            $facts[] = [
                'age_bucket' => $bucket,
                'enqueued_at' => $enqueued,
                'observed_at' => $observedAt,
                'queue_status' => (string) ($p['queue_status'] ?? ''),
                'stale_risk' => $this->staleRisk($bucket),
                'task_packet_id' => (string) ($p['task_packet_id'] ?? ''),
                'time_in_queue_seconds' => $seconds,
                'age_seconds' => $seconds,
                'value_class' => $valueClass,
                'freshness_status' => $this->freshnessStatus($bucket, $valueClass),
            ];
        }

        $riskRank = ['high' => 3, 'medium' => 2, 'low' => 1, 'none' => 0];
        usort($facts, static function (array $a, array $b) use ($riskRank): int {
            return ($riskRank[$b['stale_risk']] ?? 0) <=> ($riskRank[$a['stale_risk']] ?? 0)
                ?: $b['time_in_queue_seconds'] <=> $a['time_in_queue_seconds']
                ?: strcmp((string) $a['task_packet_id'], (string) $b['task_packet_id']);
        });

        return $facts;
    }

    private function ageBucket(int $seconds): string
    {
        if ($seconds >= 3600) {
            return 'critical';
        }
        if ($seconds >= 300) {
            return 'stale';
        }
        if ($seconds >= 60) {
            return 'aging';
        }

        return 'fresh';
    }

    private function staleRisk(string $bucket): string
    {
        return match ($bucket) {
            'critical' => 'high',
            'stale' => 'medium',
            'aging' => 'low',
            default => 'none',
        };
    }

    /** @param array<string,mixed> $packet */
    private function valueClass(array $packet): string
    {
        $expectedValue = (float) ($packet['expected_value'] ?? 0.0);

        return $expectedValue >= self::HIGH_VALUE_THRESHOLD ? self::VALUE_CLASS_HIGH : self::VALUE_CLASS_LOW;
    }

    private function freshnessStatus(string $bucket, string $valueClass): string
    {
        if (! in_array($bucket, ['stale', 'critical'], true)) {
            return self::FRESHNESS_WORKER_READY;
        }

        return $valueClass === self::VALUE_CLASS_HIGH ? self::FRESHNESS_RESCUE_CANDIDATE : self::FRESHNESS_STALE_LOW_VALUE;
    }

    private function isoToTs(string $iso): int
    {
        if ($iso === '') {
            return 0;
        }
        $t = strtotime($iso);

        return $t === false ? 0 : $t;
    }
}
