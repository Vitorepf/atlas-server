<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Final-completion readiness policy for Self-Construction. Returns READY only when every ORDINARY
 * construction capability is OWNED by the Atlas server/runtime AND verified by evidence. Separates
 * the OPTIONAL oversight/emergency controls (operator) from the steady-state OWNERSHIP set.
 *
 * INPUT FACTS:
 *   { ordinary_capabilities:array<capability_id, {owner:string, verified:bool, evidence_ref?:string}>,
 *     oversight_capabilities:array<capability_id, {owner:string}>,  // optional/emergency only
 *     emergency_capabilities:array<capability_id, {owner:string}>   // optional/emergency only
 *   }
 *
 * REQUIRED ordinary_capabilities (each MUST be owned by 'atlas_native' AND verified=true AND have a
 * non-empty evidence_ref):
 *   inspect_task_packet, prepare_patch_plan, apply_scoped_patch, run_gates, write_evidence,
 *   request_rollback, learn_from_receipt, route_to_worker, decide_release
 *
 * OUTCOMES:
 *   ready                       — all required capabilities owned by atlas_native + verified + evidence present
 *   blocked_by_dependency       — any required capability owned by a non-Atlas-native owner
 *   blocked_by_missing_evidence — any required capability missing evidence_ref or verified !== true
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (blockers sorted).
 *   - PURE.
 *   - NO scalar score / rank.
 */
final class AtlasSelfConstructionAtlasNativeReadinessPolicy
{
    public const SCHEMA = 'atlas.completion.atlas_native_readiness.v1';

    public const OUTCOME_READY = 'ready';

    public const OUTCOME_BLOCKED_DEPENDENCY = 'blocked_by_dependency';

    public const OUTCOME_BLOCKED_EVIDENCE = 'blocked_by_missing_evidence';

    public const ATLAS_NATIVE_OWNER = 'atlas_native';

    public const REQUIRED_ORDINARY_CAPABILITIES = [
        'inspect_task_packet',
        'prepare_patch_plan',
        'apply_scoped_patch',
        'run_gates',
        'write_evidence',
        'request_rollback',
        'learn_from_receipt',
        'route_to_worker',
        'decide_release',
        // continuous-runtime capabilities
        'replenish_queue',
        'supervise_worker_pool',
        'keep_context_fresh',
        'repair_poison_packet',
        'sync_knowledge',
    ];

    /**
     * @param  array{
     *     ordinary_capabilities?:array<string, array{owner?:string, verified?:bool, evidence_ref?:string}>,
     *     oversight_capabilities?:array<string,array<string,mixed>>,
     *     emergency_capabilities?:array<string,array<string,mixed>>
     * }  $facts
     * @return array{schema:string, outcome:string, blockers:list<string>, owned_by_atlas:list<string>, oversight_capabilities:list<string>, emergency_capabilities:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $ordinary = is_array($facts['ordinary_capabilities'] ?? null) ? $facts['ordinary_capabilities'] : [];
        $dependencyBlockers = [];
        $evidenceBlockers = [];
        $ownedByAtlas = [];

        foreach (self::REQUIRED_ORDINARY_CAPABILITIES as $cap) {
            $row = is_array($ordinary[$cap] ?? null) ? $ordinary[$cap] : null;
            if ($row === null) {
                $evidenceBlockers[] = 'missing_capability:'.$cap;

                continue;
            }
            $owner = (string) ($row['owner'] ?? '');
            if ($owner !== self::ATLAS_NATIVE_OWNER) {
                $dependencyBlockers[] = 'non_atlas_native_owner:'.$cap.':'.($owner === '' ? 'missing' : $owner);

                continue;
            }
            $verified = (bool) ($row['verified'] ?? false);
            $evidenceRef = (string) ($row['evidence_ref'] ?? '');
            if (! $verified) {
                $evidenceBlockers[] = 'unverified:'.$cap;

                continue;
            }
            if ($evidenceRef === '') {
                $evidenceBlockers[] = 'missing_evidence_ref:'.$cap;

                continue;
            }
            $ownedByAtlas[] = $cap;
        }

        $outcome = self::OUTCOME_READY;
        if ($dependencyBlockers !== []) {
            $outcome = self::OUTCOME_BLOCKED_DEPENDENCY;
        } elseif ($evidenceBlockers !== []) {
            $outcome = self::OUTCOME_BLOCKED_EVIDENCE;
        }

        $blockers = array_merge($dependencyBlockers, $evidenceBlockers);
        sort($blockers, SORT_STRING);
        sort($ownedByAtlas, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'blockers' => $blockers,
            'owned_by_atlas' => $ownedByAtlas,
            'oversight_capabilities' => array_keys((array) ($facts['oversight_capabilities'] ?? [])),
            'emergency_capabilities' => array_keys((array) ($facts['emergency_capabilities'] ?? [])),
        ];
    }
}
