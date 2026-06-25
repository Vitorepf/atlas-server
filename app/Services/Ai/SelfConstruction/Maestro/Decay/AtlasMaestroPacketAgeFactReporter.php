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
            $facts[] = [
                'enqueued_at' => $enqueued,
                'observed_at' => $observedAt,
                'queue_status' => (string) ($p['queue_status'] ?? ''),
                'task_packet_id' => (string) ($p['task_packet_id'] ?? ''),
                'time_in_queue_seconds' => max(0, $observedTs - $ts),
            ];
        }

        usort($facts, static fn (array $a, array $b): int => strcmp((string) $a['enqueued_at'], (string) $b['enqueued_at']));

        return $facts;
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
