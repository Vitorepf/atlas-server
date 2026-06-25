<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\E2E;

/**
 * Composes an ATLAS-NATIVE end-to-end Self-Construction CYCLE SCENARIO from per-organ FACTS. Each
 * step carries owner organ + input facts + output evidence + rollback expectation + next-step
 * dependency. Pure: NEVER executes providers, never mutates code.
 *
 * INPUT FACTS (per organ — each step requires its own FACT bundle to be 'covered'):
 *   { cortex, goal_value, strategy, architecture, task_fabric, maestro, worker_swarm,
 *     verification_court, merge_governor, receipts, learning_transfer, knowledge_sync, autopoiesis }
 *
 * OUTPUT:
 *   { schema, status ∈ {ready,blocked}, blockers:list<string>, steps:list<step>,
 *     autonomy_owner:'atlas_native' }
 *
 * BLOCKERS:
 *   - missing_organ_facts:<organ>           — facts.<organ> not supplied
 *   - autonomy_owner_mismatch:<value>       — facts.cortex.autonomy_owner != 'atlas_native'
 *
 * INVARIANTS:
 *   - DETERMINISTIC ordering (canonical STEP_ORDER below).
 *   - PURE.
 */
final class AtlasSelfConstructionEndToEndCycleScenarioBuilder
{
    public const SCHEMA = 'atlas.selfconstruction.e2e_cycle_scenario.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const REQUIRED_AUTONOMY_OWNER = 'atlas_native';

    /** Canonical step order — also the dependency chain (each step's next_step_dependency is the next entry). */
    public const STEP_ORDER = [
        'cortex',
        'goal_value',
        'strategy',
        'architecture',
        'task_fabric',
        'maestro',
        'worker_swarm',
        'verification_court',
        'merge_governor',
        'receipts',
        'learning_transfer',
        'knowledge_sync',
        'autopoiesis',
    ];

    /** Per-step expected_evidence_keys + rollback_expectation. */
    public const STEP_SPEC = [
        'cortex' => ['evidence' => 'world_snapshot_hash', 'rollback' => 'none_required (read_only)'],
        'goal_value' => ['evidence' => 'value_facts_list', 'rollback' => 'none_required (read_only)'],
        'strategy' => ['evidence' => 'decision_hash', 'rollback' => 'revert_decision_record'],
        'architecture' => ['evidence' => 'slice_briefs_list', 'rollback' => 'discard_unmerged_briefs'],
        'task_fabric' => ['evidence' => 'packet_spec_hashes', 'rollback' => 'cancel_packets_before_dispatch'],
        'maestro' => ['evidence' => 'lease_ids', 'rollback' => 'revoke_lease'],
        'worker_swarm' => ['evidence' => 'evidence_hash', 'rollback' => 'discard_unmerged_diff'],
        'verification_court' => ['evidence' => 'verdict_hash', 'rollback' => 'mark_verdict_void'],
        'merge_governor' => ['evidence' => 'release_decision_hash', 'rollback' => 'execute_rollback_plan'],
        'receipts' => ['evidence' => 'receipt_chain_hash', 'rollback' => 'none_required (append_only)'],
        'learning_transfer' => ['evidence' => 'lesson_record_id', 'rollback' => 'mark_lesson_quarantined'],
        'knowledge_sync' => ['evidence' => 'sync_run_id', 'rollback' => 'rerun_sync'],
        'autopoiesis' => ['evidence' => 'experiment_plan_hash', 'rollback' => 'discard_plan'],
    ];

    /**
     * @param  array<string,array<string,mixed>>  $facts
     * @return array{schema:string, status:string, blockers:list<string>, autonomy_owner:string, steps:list<array<string,mixed>>}
     */
    public function build(array $facts): array
    {
        $blockers = [];

        // Autonomy owner check via cortex facts.
        $cortex = is_array($facts['cortex'] ?? null) ? $facts['cortex'] : [];
        $owner = (string) ($cortex['autonomy_owner'] ?? self::REQUIRED_AUTONOMY_OWNER);
        if ($owner !== self::REQUIRED_AUTONOMY_OWNER) {
            $blockers[] = 'autonomy_owner_mismatch:'.$owner;
        }

        $steps = [];
        $stepCount = count(self::STEP_ORDER);
        foreach (self::STEP_ORDER as $i => $organ) {
            $orgFacts = is_array($facts[$organ] ?? null) ? $facts[$organ] : null;
            if ($orgFacts === null) {
                $blockers[] = 'missing_organ_facts:'.$organ;
            }
            $spec = self::STEP_SPEC[$organ];
            $next = $i + 1 < $stepCount ? self::STEP_ORDER[$i + 1] : null;
            $steps[] = [
                'organ' => $organ,
                'owner' => self::REQUIRED_AUTONOMY_OWNER,
                'input_facts' => $orgFacts ?? null,
                'output_evidence' => $spec['evidence'],
                'rollback_expectation' => $spec['rollback'],
                'next_step_dependency' => $next,
            ];
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'status' => $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'blockers' => $blockers,
            'autonomy_owner' => self::REQUIRED_AUTONOMY_OWNER,
            'steps' => $steps,
        ];
    }
}
