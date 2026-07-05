<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Bridges capability gap memories into originator-safe task constraints
 * without trusting stale provider projections.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionCortexCapabilityGapMemoryBridge
{
    public const SCHEMA = 'atlas.cortex.capability_gap_memory_bridge.v1';

    public const STATUS_FRESH = 'fresh';
    public const STATUS_STALE = 'stale';
    public const STATUS_PROJECTION_ONLY = 'projection_only';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function bridge(array $input): array
    {
        $memories = is_array($input['gap_memories'] ?? null) ? $input['gap_memories'] : [];
        $now = (int) ($input['now_unix'] ?? time());
        $windowSeconds = (int) ($input['freshness_window_seconds'] ?? 86400);
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $constraints = [];
        $refreshNeeded = [];

        foreach ($memories as $memory) {
            if (! is_array($memory)) {
                continue;
            }

            $id = (string) ($memory['memory_id'] ?? '');
            $source = (string) ($memory['source'] ?? '');
            $lastUnix = (int) ($memory['last_unix'] ?? 0);
            $hash = (string) ($memory['hash'] ?? '');
            $capability = (string) ($memory['capability'] ?? '');
            $gap = (string) ($memory['gap'] ?? '');

            if ($id === '' || $capability === '' || $gap === '') {
                continue;
            }

            if ($source === 'provider_projection') {
                $refreshNeeded[] = [
                    'memory_id' => $id,
                    'reason' => 'projection_only_not_trusted',
                ];

                continue;
            }

            if ($hash === '' || $lastUnix <= 0) {
                $refreshNeeded[] = [
                    'memory_id' => $id,
                    'reason' => 'missing_hash_or_timestamp',
                ];

                continue;
            }

            $age = $now - $lastUnix;
            if ($age > $windowSeconds) {
                $refreshNeeded[] = [
                    'memory_id' => $id,
                    'reason' => 'stale:age_'.$age.'s_exceeds_window_'.$windowSeconds.'s',
                ];

                continue;
            }

            $constraints[] = [
                'memory_id' => $id,
                'capability' => $capability,
                'gap' => $gap,
                'constraint' => "address_capability_gap:{$capability}:{$gap}",
                'status' => self::STATUS_FRESH,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'constraints' => $constraints,
            'refresh_needed' => $refreshNeeded,
            'constraint_count' => count($constraints),
            'refresh_count' => count($refreshNeeded),
            'trusted' => $refreshNeeded === [],
        ];
    }
}
