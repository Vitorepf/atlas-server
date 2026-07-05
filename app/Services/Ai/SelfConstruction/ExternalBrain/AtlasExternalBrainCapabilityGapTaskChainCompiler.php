<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compiles 95-percent maturity-gap blockers into ordered, muscle-ready task chains instead of
 * loose one-off tasks. Context proof always precedes gate hardening, and runtime integration
 * always depends on both — the fixed blocker order below encodes that dependency shape. Two
 * gaps sharing the same `unblocker_id` collapse into ONE task node that both chains point to,
 * so a shared unblocker is never duplicated across gaps.
 *
 * Each task node exposes `unlocks` — the task_ids that this task directly unblocks (the reverse
 * of dependency_ids). Orphan tasks (no dependents and no dependencies) or contradictory
 * dependencies (a task whose dependency is not in the chain) are marked `not_ready` instead
 * of emitting a fake coherent chain.
 *
 * Chain value scoring: each chain gets a `chain_value_score` (0.0–1.0) reflecting how many
 * blockers are ordered, non-orphan, and have concrete allowed_files_hint.
 *
 * Input shape:
 *   { gaps: list<{ gap_id:string, blockers: list<{
 *       type: 'missing_context'|'weak_gate'|'no_runtime_integration',
 *       unblocker_id?: string,
 *       allowed_files_hint?: list<string>,
 *       expected_delta?: string,
 *       acceptance_strength?: string,
 *   }> }> }
 *
 * Pure / deterministic. No I/O — callers persist/act on the returned chain.
 */
final class AtlasExternalBrainCapabilityGapTaskChainCompiler
{
    public const SCHEMA = 'atlas.self_construction.external_brain.capability_gap_task_chain_compiler.v1';

    /** Fixed dependency order: context proof before gate hardening before runtime integration. */
    private const BLOCKER_ORDER = [
        'missing_context' => 0,
        'weak_gate' => 1,
        'no_runtime_integration' => 2,
    ];

    private const BLOCKER_DEFAULT_ACCEPTANCE = [
        'missing_context' => 'context_proof_required',
        'weak_gate' => 'gate_hardening_proof_required',
        'no_runtime_integration' => 'runtime_integration_proof_required',
    ];

    private const BLOCKER_DEFAULT_EXPECTED_DELTA = [
        'missing_context' => 'closes the missing-context evidence gap for this capability',
        'weak_gate' => 'hardens the acceptance gate so it can no longer be satisfied by proxy evidence',
        'no_runtime_integration' => 'wires the capability into a real, invoked production call path',
    ];

    /** Every muscle-ready spec contract demands the same baseline runnable proof. */
    private const REQUIRED_EVIDENCE = ['tests_or_gates_result', 'implementation_notes'];

    /**
     * @param  array{gaps?: list<array{gap_id?: string, blockers?: list<array<string,mixed>>}>}  $facts
     * @return array{schema:string, chain:list<array<string,mixed>>, gap_chains:array<string,list<string>>}
     */
    public function compile(array $facts): array
    {
        $chain = [];
        $gapChains = [];
        $taskIdByUnblockerKey = [];
        $chainIndexByUnblockerKey = [];

        foreach ((array) ($facts['gaps'] ?? []) as $gap) {
            if (! is_array($gap)) {
                continue;
            }
            $gapId = trim((string) ($gap['gap_id'] ?? ''));
            if ($gapId === '') {
                continue;
            }

            $blockers = array_values(array_filter(
                (array) ($gap['blockers'] ?? []),
                static fn ($b): bool => is_array($b) && array_key_exists('type', $b) && array_key_exists((string) $b['type'], self::BLOCKER_ORDER),
            ));
            usort($blockers, static fn (array $a, array $b): int => self::BLOCKER_ORDER[$a['type']] <=> self::BLOCKER_ORDER[$b['type']]);

            $gapTaskIds = [];
            $previousTaskId = null;

            foreach ($blockers as $blocker) {
                $type = (string) $blocker['type'];
                $unblockerId = trim((string) ($blocker['unblocker_id'] ?? ''));
                $unblockerKey = $unblockerId !== '' ? $unblockerId : $gapId.':'.$type;

                $proofContract = [
                    'acceptance_strength' => (string) ($blocker['acceptance_strength'] ?? self::BLOCKER_DEFAULT_ACCEPTANCE[$type]),
                    'expected_delta' => (string) ($blocker['expected_delta'] ?? self::BLOCKER_DEFAULT_EXPECTED_DELTA[$type]),
                    'allowed_files_hint' => array_values((array) ($blocker['allowed_files_hint'] ?? [])),
                ];

                if (! array_key_exists($unblockerKey, $taskIdByUnblockerKey)) {
                    $taskId = $unblockerKey;
                    $taskIdByUnblockerKey[$unblockerKey] = $taskId;
                    $chainIndexByUnblockerKey[$unblockerKey] = count($chain);

                    $chain[] = [
                        'task_id' => $taskId,
                        'gap_id' => $gapId,
                        'gap_ids' => [$gapId],
                        'dependent_gap_ids' => [$gapId],
                        'blocker_type' => $type,
                        'dependency_ids' => $previousTaskId !== null ? [$previousTaskId] : [],
                        'allowed_files_hint' => $proofContract['allowed_files_hint'],
                        'acceptance_strength' => $proofContract['acceptance_strength'],
                        'expected_delta' => $proofContract['expected_delta'],
                        'proof_contracts_by_gap' => [$gapId => $proofContract],
                        'reuse_reason' => $unblockerId !== '' ? "shared unblocker_id: {$unblockerId}" : null,
                    ];
                } else {
                    // Shared unblocker: another gap references the same node — record it without duplicating the node.
                    $idx = $chainIndexByUnblockerKey[$unblockerKey];
                    if (! in_array($gapId, $chain[$idx]['gap_ids'], true)) {
                        $chain[$idx]['gap_ids'][] = $gapId;
                        $chain[$idx]['dependent_gap_ids'][] = $gapId;
                    }
                    // Preserve this gap's own proof contract even though the task node is shared.
                    $chain[$idx]['proof_contracts_by_gap'][$gapId] = $proofContract;
                }

                $taskId = $taskIdByUnblockerKey[$unblockerKey];
                $gapTaskIds[] = $taskId;
                $previousTaskId = $taskId;
            }

            $gapChains[$gapId] = $gapTaskIds;
        }

        // Computed last, over the FINAL node (gap_ids/proof_contracts_by_gap keep growing for a
        // shared unblocker while later gaps are processed), so the contract always reflects every
        // gap that ended up pointing at this node.
        foreach ($chain as &$node) {
            $node['muscle_ready_spec_contract'] = $this->buildMuscleReadySpecContract($node);
        }
        unset($node);

        // Post-processing: unlocks, not_ready, chain_value_score.
        $chain = $this->computeUnlocksAndReadiness($chain);

        return [
            'schema' => self::SCHEMA,
            'chain' => $chain,
            'gap_chains' => $gapChains,
            'chain_value_score' => $this->computeChainValueScore($chain),
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array{objective_seed:string, allowed_files_hint:list<string>, runnable_acceptance_seed:string,
     *     required_evidence:list<string>, dependency_ids:list<string>, per_gap_proof_contracts:array<string,array<string,mixed>>,
     *     not_muscle_ready:bool}
     */
    private function buildMuscleReadySpecContract(array $node): array
    {
        $allowedFilesHint = array_values((array) ($node['allowed_files_hint'] ?? []));

        return [
            'objective_seed' => (string) ($node['expected_delta'] ?? ''),
            'allowed_files_hint' => $allowedFilesHint,
            'runnable_acceptance_seed' => (string) ($node['acceptance_strength'] ?? ''),
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'dependency_ids' => array_values((array) ($node['dependency_ids'] ?? [])),
            // Shared unblockers merge gap_ids/dependent_gap_ids at the node level, but each gap's
            // own proof contract must stay distinguishable inside the spec contract too — a
            // downstream muscle task consuming this contract must not lose which gap demanded what.
            'per_gap_proof_contracts' => (array) ($node['proof_contracts_by_gap'] ?? []),
            // A gap-chain node with no concrete file target is an abstract blocker, not an
            // implementable task — never let a muscle treat it as ready-to-claim.
            'not_muscle_ready' => $allowedFilesHint === [],
        ];
    }

    /**
     * Compute unlocks (reverse dependencies), detect orphans and contradictory
     * dependencies, and mark affected nodes as not_ready.
     *
     * @param  list<array<string,mixed>>  $chain
     * @return list<array<string,mixed>>
     */
    private function computeUnlocksAndReadiness(array $chain): array
    {
        $taskIds = [];
        foreach ($chain as $node) {
            $taskIds[$node['task_id']] = true;
        }

        // Build unlocks: for each task, which tasks depend on it?
        $unlocks = [];
        foreach ($chain as $node) {
            $unlocks[$node['task_id']] = [];
        }
        foreach ($chain as $node) {
            foreach (($node['dependency_ids'] ?? []) as $depId) {
                if (isset($unlocks[$depId])) {
                    $unlocks[$depId][] = $node['task_id'];
                }
            }
        }

        foreach ($chain as &$node) {
            $node['unlocks'] = array_values(array_unique($unlocks[$node['task_id']] ?? []));

            $hasDeps      = ! empty($node['dependency_ids']);
            $hasUnlocks   = ! empty($node['unlocks']);
            $depsInChain  = true;
            foreach (($node['dependency_ids'] ?? []) as $depId) {
                if (! isset($taskIds[$depId])) {
                    $depsInChain = false;
                    break;
                }
            }

            // Orphan: no dependencies and no dependents (isolated task).
            $isOrphan = ! $hasDeps && ! $hasUnlocks;
            // Contradictory: a dependency references a task_id not in the chain.
            $isContradictory = ! $depsInChain;

            $node['not_ready'] = $isOrphan || $isContradictory;
            if ($isOrphan) {
                $node['not_ready_reason'] = 'orphan_task';
            } elseif ($isContradictory) {
                $node['not_ready_reason'] = 'contradictory_dependency';
            } else {
                $node['not_ready_reason'] = null;
            }
        }
        unset($node);

        return $chain;
    }

    /**
     * Chain value score: fraction of nodes that are ready (not orphan, not
     * contradictory) and have concrete allowed_files_hint.
     *
     * @param  list<array<string,mixed>>  $chain
     */
    private function computeChainValueScore(array $chain): float
    {
        if ($chain === []) {
            return 0.0;
        }

        $ready = 0;
        foreach ($chain as $node) {
            if (empty($node['not_ready']) && ! empty($node['allowed_files_hint'])) {
                $ready++;
            }
        }

        return round($ready / count($chain), 4);
    }
}
