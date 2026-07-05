<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Pure veto that rejects candidate batches that optimize queue depth, test count
 * or wrapper count while leaving capability unchanged (Goodhart's Law defense).
 *
 * Veto rules:
 *   - Proxy-only wins (queue depth, test count, wrapper count) without capability change → VETO
 *   - Capability-changing repair or learning batches → PASS
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasStrategyCouncilAntiGoodhartVeto
{
    public const SCHEMA = 'atlas.strategy_council.anti_goodhart_veto.v1';

    private const PROXY_MARKERS = [
        'queue depth', 'queue_depth', 'test count', 'test_count',
        'wrapper count', 'wrapper_count', 'task count', 'task_count',
        'more tasks', 'more tests', 'more wrappers',
    ];

    private const CAPABILITY_MARKERS = [
        'capability', 'repair', 'learning', 'autonomy', 'collision_prevention',
        'closed_loop', 'blocker_removal', 'capability_expansion',
    ];

    /**
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    public function veto(array $batch): array
    {
        $objective = strtolower(trim((string) ($batch['objective'] ?? '')));
        $capabilityChange = strtolower(trim((string) ($batch['capability_change'] ?? '')));
        $combined = $objective.' '.$capabilityChange;

        $hasProxy = false;
        foreach (self::PROXY_MARKERS as $marker) {
            if (str_contains($combined, $marker)) {
                $hasProxy = true;
                break;
            }
        }

        $hasCapabilityChange = false;
        foreach (self::CAPABILITY_MARKERS as $marker) {
            if (str_contains($combined, $marker)) {
                $hasCapabilityChange = true;
                break;
            }
        }

        $vetoed = $hasProxy && ! $hasCapabilityChange;

        $reasons = [];
        if ($vetoed) {
            $reasons[] = 'proxy_only_win_without_capability_change';
        } else {
            $reasons[] = $hasCapabilityChange ? 'capability_change_present' : 'no_proxy_optimization';
        }

        return [
            'schema_version' => self::SCHEMA,
            'vetoed' => $vetoed,
            'reasons' => $reasons,
            'has_proxy_marker' => $hasProxy,
            'has_capability_change' => $hasCapabilityChange,
        ];
    }
}
