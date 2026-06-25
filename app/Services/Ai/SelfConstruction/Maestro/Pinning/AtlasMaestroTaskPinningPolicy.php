<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Pinning;

/**
 * Decides whether a worker may claim a (possibly pinned) task. Consumes ONLY
 * the pinning registry — does not touch the queue. Pure FACT-driven outcome.
 */
final class AtlasMaestroTaskPinningPolicy
{
    public const DECISION_ALLOW = 'allow';
    public const DECISION_REFUSE = 'refuse';
    public const REASON_NO_PIN = 'no_pin';
    public const REASON_PIN_MATCH = 'pin_match';
    public const REASON_PIN_CONFLICT = 'pinned_to_other_worker';
    public const REASON_INVALID_WORKER = 'invalid_worker_id';

    public function __construct(private readonly AtlasMaestroTaskPinningRegistry $registry) {}

    /**
     * @return array{decision:string, reason:string, task_packet_id:string, worker_id:string, pinned_worker_id:?string}
     */
    public function decide(string $taskPacketId, string $workerId): array
    {
        $base = [
            'pinned_worker_id' => null,
            'task_packet_id' => $taskPacketId,
            'worker_id' => $workerId,
        ];

        if (trim($workerId) === '') {
            return $base + ['decision' => self::DECISION_REFUSE, 'reason' => self::REASON_INVALID_WORKER];
        }

        $pin = $this->registry->lookup($taskPacketId);
        if ($pin === null) {
            return $base + ['decision' => self::DECISION_ALLOW, 'reason' => self::REASON_NO_PIN];
        }

        $pinned = (string) ($pin['worker_id'] ?? '');
        if ($pinned === $workerId) {
            return ['pinned_worker_id' => $pinned, 'task_packet_id' => $taskPacketId, 'worker_id' => $workerId,
                'decision' => self::DECISION_ALLOW, 'reason' => self::REASON_PIN_MATCH];
        }

        return ['pinned_worker_id' => $pinned, 'task_packet_id' => $taskPacketId, 'worker_id' => $workerId,
            'decision' => self::DECISION_REFUSE, 'reason' => self::REASON_PIN_CONFLICT];
    }
}
