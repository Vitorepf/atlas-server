<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed reachability gate: computes whether a deletion candidate is REACHABLE from any of
 * Atlas's real entry points — routes, console commands, scheduled jobs, consumers (queue/event
 * listeners), or tests — before {@see AtlasExternalBrainDeadCodeProofCollector} or a simplification
 * planner may treat it as safe to delete.
 *
 * A symbol is reachable when ANY entry-point reference is found. A symbol suspected of being
 * referenced only dynamically (call_user_func, variable method/class name, reflection, service
 * container string binding) can NEVER be proven unreachable by a static search — it reports
 * unknown_reachability=true and deletion_eligible is forced false, regardless of what the static
 * entry-point facts say. Deletion is eligible ONLY when reachability is both proven false AND not
 * unknown.
 *
 * Input shape:
 *   { target: {
 *       symbol?:                     string,
 *       route_reference_found?:      bool,
 *       command_reference_found?:    bool,
 *       job_reference_found?:        bool,  // scheduled/cron job references the target
 *       consumer_reference_found?:   bool,  // queue/event listener references the target
 *       test_reference_found?:       bool,
 *       dynamic_reference_suspected?: bool,
 *   } }
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the entry-point facts.
 */
final class AtlasExternalBrainCodePathReachabilityMap
{
    public const SCHEMA = 'atlas.self_construction.external_brain.code_path_reachability_map.v1';

    private const ENTRY_POINT_FLAGS = [
        'route_reference_found' => 'route',
        'command_reference_found' => 'command',
        'job_reference_found' => 'job',
        'consumer_reference_found' => 'consumer',
        'test_reference_found' => 'test',
    ];

    /**
     * @param  array{target?: array<string,mixed>}  $facts
     * @return array<string,mixed>
     */
    public function map(array $facts): array
    {
        $target = is_array($facts['target'] ?? null) ? $facts['target'] : [];

        $symbol = trim((string) ($target['symbol'] ?? ''));
        $dynamicSuspected = (bool) ($target['dynamic_reference_suspected'] ?? false);

        $reachableVia = [];
        foreach (self::ENTRY_POINT_FLAGS as $flag => $label) {
            if ((bool) ($target[$flag] ?? false)) {
                $reachableVia[] = $label;
            }
        }

        // A static search can never prove the absence of a dynamic call site — fail closed to
        // unknown_reachability regardless of what the static entry-point facts show.
        if ($dynamicSuspected) {
            return [
                'schema' => self::SCHEMA,
                'symbol' => $symbol,
                'reachable' => $reachableVia !== [],
                'reachable_via' => $reachableVia,
                'unknown_reachability' => true,
                'deletion_eligible' => false,
                'reason' => 'dynamic_reference_suspected: static search cannot prove absence of a call_user_func/variable-method/reflection/container-string reference',
            ];
        }

        $reachable = $reachableVia !== [];

        return [
            'schema' => self::SCHEMA,
            'symbol' => $symbol,
            'reachable' => $reachable,
            'reachable_via' => $reachableVia,
            'unknown_reachability' => false,
            'deletion_eligible' => ! $reachable,
            'reason' => $reachable
                ? 'reachable via: '.implode(', ', $reachableVia)
                : 'no route, command, job, consumer, or test reference found',
        ];
    }
}
