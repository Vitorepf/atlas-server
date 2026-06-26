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
}