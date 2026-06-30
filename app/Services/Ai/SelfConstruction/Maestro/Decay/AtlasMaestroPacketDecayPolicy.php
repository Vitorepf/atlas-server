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
    /** Poison/give_back family decays at this fraction of the normal threshold. */
    public const POISON_THRESHOLD_RATIO = 0.5;

    /** @var Closure():int */
    private Closure $thresholdProvider;

    /** @var Closure():bool */
    private Closure $masterEnabled;

    /** @var callable|null Extra-context provider: (task_packet_id) => ['dependency_critical'=>bool, 'give_back_count'=>int, ...] */
    private $packetMetaProvider;

    public function __construct(
        private readonly AtlasMaestroPacketAgeFactReporter $reporter,
        ?callable $thresholdSeconds = null,
        ?callable $masterEnabled = null,
        ?callable $packetMetaProvider = null,
    ) {
        $this->thresholdProvider = Closure::fromCallable(
            $thresholdSeconds ?? static fn (): int => (int) config('atlas.loop.maestro.packet_decay.threshold_seconds', 21600),
        );
        $this->masterEnabled = Closure::fromCallable(
            $masterEnabled ?? static fn (): bool => (bool) env('ATLAS_LOOP_MASTER_ENABLED', false),
        );
        $this->packetMetaProvider = $packetMetaProvider;
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

        $poisonThreshold = (int) round($threshold * self::POISON_THRESHOLD_RATIO);

        $proposals = [];
        foreach ($this->reporter->report() as $fact) {
            $age = (int) ($fact['time_in_queue_seconds'] ?? 0);
            $status = (string) ($fact['queue_status'] ?? '');
            $id = (string) ($fact['task_packet_id'] ?? '');

            $meta = $this->packetMetaProvider !== null ? (array) ($this->packetMetaProvider)($id) : [];
            $critical = (bool) ($meta['dependency_critical'] ?? false);
            $poisonFamily = (int) ($meta['give_back_count'] ?? 0) > 0 || (bool) ($meta['poison'] ?? false);

            // Dependency-critical packets that are stale must be kept explicitly, never parked.
            if ($critical && $age > $threshold && in_array($status, self::UNCLAIMED_STATUSES, true)) {
                $proposals[] = [
                    'age_seconds' => $age,
                    'proposed_action' => 'keep',
                    'reason' => 'keep_due_to_critical_dependency',
                    'task_packet_id' => $id,
                    'threshold_seconds' => $threshold,
                ];
                continue;
            }

            if (! in_array($status, self::UNCLAIMED_STATUSES, true)) {
                continue;
            }

            // Poison/give_back family uses a lower threshold so stale waste exits sooner.
            if ($poisonFamily && $age > $poisonThreshold) {
                $proposals[] = [
                    'age_seconds' => $age,
                    'proposed_action' => self::PROPOSED_ACTION,
                    'reason' => sprintf('park_due_to_poison_age age_seconds=%d exceeds poison_threshold_seconds=%d', $age, $poisonThreshold),
                    'task_packet_id' => $id,
                    'threshold_seconds' => $threshold,
                ];
                continue;
            }

            if ($age <= $threshold) {
                continue;
            }

            $proposals[] = [
                'age_seconds' => $age,
                'proposed_action' => self::PROPOSED_ACTION,
                'reason' => sprintf('age_seconds=%d exceeds threshold_seconds=%d', $age, $threshold),
                'task_packet_id' => $id,
                'threshold_seconds' => $threshold,
            ];
        }

        return $proposals;
    }
}
