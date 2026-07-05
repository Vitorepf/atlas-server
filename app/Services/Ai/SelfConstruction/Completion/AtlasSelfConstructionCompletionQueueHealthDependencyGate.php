<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Makes queue health a hard dependency for completion readiness so
 * lease leaks and malformed blockers cannot be ignored.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionCompletionQueueHealthDependencyGate
{
    public const SCHEMA = 'atlas.self_construction.completion_queue_health_dependency_gate.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $leaseLeakDetected = (bool) ($input['lease_leak_detected'] ?? false);
        $malformedBlockerCount = (int) ($input['malformed_blocker_count'] ?? 0);
        $claimableDepth = (int) ($input['claimable_depth'] ?? 10);

        $blockers = [];
        $verifyCommands = [];

        if ($leaseLeakDetected) {
            $blockers[] = 'lease_leak_detected';
            $verifyCommands[] = 'php artisan atlas:task:lease-audit --json';
        }
        if ($malformedBlockerCount > 0) {
            $blockers[] = 'malformed_blockers:'.$malformedBlockerCount;
            $verifyCommands[] = 'php artisan atlas:task:malformed-sweep --json';
        }
        if ($claimableDepth === 0) {
            $blockers[] = 'dry_queue';
            $verifyCommands[] = 'php artisan atlas:task:queue-health --json';
        }

        $ready = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'ready' => $ready,
            'blockers' => $blockers,
            'verify_commands' => $verifyCommands,
            'lease_leak_detected' => $leaseLeakDetected,
            'malformed_blocker_count' => $malformedBlockerCount,
            'claimable_depth' => $claimableDepth,
        ];
    }
}
