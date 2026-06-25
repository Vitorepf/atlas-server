<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Decay;

use Closure;

/**
 * Advisory policy — proposes packets for parking when their age exceeds threshold
 * AND the queue_status indicates unclaimed. Reads FACTS from AtlasMaestroPacketAgeFactReporter.
 *
 * Never mutates queue state. Never auto-parks. Operator/CLI decides downstream.
 */
final class AtlasMaestroPacketDecayPolicy
{
    public const SCHEMA = 'atlas.maestro.packet_decay_policy.v1';
    public const PROPOSED_ACTION = 'park';
    public const UNCLAIMED_STATUSES = ['waiting', 'queued', 'enqueued', 'pending'];

    /** @var Closure():int */
    private Closure $thresholdProvider;

    /** @var Closure():bool */
    private Closure $masterEnabled;

    public function __construct(
        private readonly AtlasMaestroPacketAgeFactReporter $reporter,
        ?callable $thresholdSeconds = null,
        ?callable $masterEnabled = null,
    ) {
        $this->thresholdProvider = Closure::fromCallable(
            $thresholdSeconds ?? static fn (): int => (int) config('atlas.loop.maestro.packet_decay.threshold_seconds', 21600),
        );
        $this->masterEnabled = Closure::fromCallable(
            $masterEnabled ?? static fn (): bool => (bool) env('ATLAS_LOOP_MASTER_ENABLED', false),
        );
    }

    /**
     * @return list<array{task_packet_id:string, age_seconds:int, threshold_seconds:int, proposed_action:string, reason:string}>
     */
    public function propose(): array
    {
        if (! ($this->masterEnabled)()) {
            return [];
        }
        $threshold = (int) ($this->thresholdProvider)();
        if ($threshold <= 0) {
            return [];
        }

        $proposals = [];
        foreach ($this->reporter->report() as $fact) {
            $age = (int) ($fact['time_in_queue_seconds'] ?? 0);
            $status = (string) ($fact['queue_status'] ?? '');
            if ($age <= $threshold) {
                continue;
            }
            if (! in_array($status, self::UNCLAIMED_STATUSES, true)) {
                continue;
            }
            $proposals[] = [
                'age_seconds' => $age,
                'proposed_action' => self::PROPOSED_ACTION,
                'reason' => sprintf('age_seconds=%d exceeds threshold_seconds=%d', $age, $threshold),
                'task_packet_id' => (string) ($fact['task_packet_id'] ?? ''),
                'threshold_seconds' => $threshold,
            ];
        }

        return $proposals;
    }
}
