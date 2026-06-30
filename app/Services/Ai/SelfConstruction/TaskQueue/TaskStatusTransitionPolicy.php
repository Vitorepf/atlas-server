<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQueue;

/**
 * Status-transition policy for the Agent Control Plane task packet queue
 * repository.
 *
 * Extracted from AgentControlPlaneTaskPacketQueueRepository to reduce the
 * god-class. All methods are pure / stateless.
 */
final class TaskStatusTransitionPolicy
{
    public const ALLOWED_STATUS_TRANSITIONS = [
        'queued' => ['claimable', 'blocked', 'cancelled'],
        'claimable' => ['claimed', 'blocked', 'cancelled'],
        'claimed' => ['lease_expired', 'released', 'completed_dry_run', 'blocked'],
        'lease_expired' => ['claimable', 'released', 'cancelled'],
        'released' => ['claimable', 'cancelled'],
        'blocked' => ['claimable', 'cancelled'],
        'completed_dry_run' => [],
        'cancelled' => [],
    ];

    public static function statusTransitionAllowed(string $previous, string $next): bool
    {
        if ($previous === $next) {
            return true;
        }

        return in_array($next, self::ALLOWED_STATUS_TRANSITIONS[$previous] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function validateTransitionMetadata(string $next, array $metadata): array
    {
        if ($next !== 'claimed') {
            return [];
        }

        $missing = [];
        foreach (['lease_id', 'agent_id'] as $field) {
            if ((string) ($metadata[$field] ?? '') === '') {
                $missing[] = $field;
            }
        }

        return $missing === []
            ? []
            : [
                'missing_metadata' => $missing,
                'claim_transition_requires_lease_id' => true,
                'claim_transition_requires_agent_id' => true,
            ];
    }

    public static function transitionPolicyHash(): string
    {
        return (new TaskPacketCanonicalizer)->stableHash(self::ALLOWED_STATUS_TRANSITIONS);
    }

    /** @return list<string> states with no allowed outgoing transitions */
    public static function terminalStatuses(): array
    {
        return array_values(array_filter(
            array_keys(self::ALLOWED_STATUS_TRANSITIONS),
            static fn (string $s): bool => self::ALLOWED_STATUS_TRANSITIONS[$s] === []
        ));
    }

    /** @return list<string> states that can re-enter the claimable pool */
    public static function recoverableStatuses(): array
    {
        return array_values(array_filter(
            array_keys(self::ALLOWED_STATUS_TRANSITIONS),
            static fn (string $s): bool => in_array('claimable', self::ALLOWED_STATUS_TRANSITIONS[$s], true)
        ));
    }

    /** @return list<string> states where an active worker lease is in play */
    public static function activeLeaseStatuses(): array
    {
        return array_values(array_filter(
            array_keys(self::ALLOWED_STATUS_TRANSITIONS),
            static fn (string $s): bool => in_array('lease_expired', self::ALLOWED_STATUS_TRANSITIONS[$s], true)
        ));
    }

    /** @return array{allowed:bool,reason?:string} */
    public static function explainTransition(string $previous, string $next): array
    {
        $known = array_keys(self::ALLOWED_STATUS_TRANSITIONS);
        if (! in_array($previous, $known, true)) {
            return ['allowed' => false, 'reason' => 'unknown_previous_status'];
        }
        if (! in_array($next, $known, true)) {
            return ['allowed' => false, 'reason' => 'unknown_next_status'];
        }
        if ($previous === $next) {
            return ['allowed' => true];
        }
        if (self::ALLOWED_STATUS_TRANSITIONS[$previous] === []) {
            return ['allowed' => false, 'reason' => 'terminal_status_cannot_transition'];
        }
        if (! in_array($next, self::ALLOWED_STATUS_TRANSITIONS[$previous], true)) {
            return ['allowed' => false, 'reason' => 'transition_not_allowed'];
        }

        return ['allowed' => true];
    }
}