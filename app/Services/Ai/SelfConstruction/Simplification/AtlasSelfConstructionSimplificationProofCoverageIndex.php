<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Indexes proof coverage (test, replay, runtime, docs, rollback) per
 * consolidation target so proof holes are visible before a deletion or
 * merge is assigned to a muscle. A target's behavior is proven when it
 * carries at least one of test/replay/runtime evidence; safe_for_consolidation
 * additionally requires rollback proof — missing either blocks consolidation.
 */
final class AtlasSelfConstructionSimplificationProofCoverageIndex
{
    private const PROOF_TYPES = ['test_proof', 'replay_proof', 'runtime_proof', 'docs_proof', 'rollback_proof'];

    private const BEHAVIOR_PROOF_TYPES = ['test_proof', 'replay_proof', 'runtime_proof'];

    private const SIMPLIFICATION_ACTION_TYPES = ['delete', 'merge'];

    /**
     * @param  array<int,array<string,mixed>>  $targets
     * @return array<string,mixed>
     */
    public function build(array $targets): array
    {
        $coverageByTarget = [];
        $missingProofs = [];
        $requiredNextTask = [];
        $allSafe = true;

        foreach ($targets as $target) {
            $name = (string) ($target['name'] ?? '');

            $flags = [];
            foreach (self::PROOF_TYPES as $type) {
                $flags[$type] = (bool) ($target[$type] ?? false);
                if (! $flags[$type]) {
                    $missingProofs[] = "{$name}:{$type}";
                }
            }

            $behaviorProof = false;
            foreach (self::BEHAVIOR_PROOF_TYPES as $type) {
                if ($flags[$type]) {
                    $behaviorProof = true;

                    break;
                }
            }

            // Risk floor: a behavior proof resting on a SINGLE evidence source (only one of
            // test/replay/runtime) is as fragile as no proof at all if that one source is wrong —
            // it must never be conflated with a multi-source, corroborated proof. Missing rollback
            // proof independently blocks the floor too: even a well-proven behavior change is unsafe
            // to consolidate with no documented way back.
            $behaviorProofSourceCount = count(array_filter(
                self::BEHAVIOR_PROOF_TYPES,
                static fn (string $type): bool => $flags[$type],
            ));
            $singleSourceBehaviorProof = $behaviorProofSourceCount === 1;
            $riskFloorReason = match (true) {
                ! $flags['rollback_proof'] && $singleSourceBehaviorProof => 'missing_rollback_proof_and_single_source_behavior_proof',
                ! $flags['rollback_proof'] => 'missing_rollback_proof',
                $singleSourceBehaviorProof => 'behavior_proof_single_source',
                default => null,
            };

            $safe = $behaviorProof && $flags['rollback_proof'] && ! $singleSourceBehaviorProof;
            $actionType = strtolower(trim((string) ($target['action_type'] ?? '')));
            $isSimplificationAction = in_array($actionType, self::SIMPLIFICATION_ACTION_TYPES, true);
            $blockedForSimplification = $isSimplificationAction && ! $behaviorProof;

            $flags['behavior_proof'] = $behaviorProof;
            $flags['safe_for_consolidation'] = $safe;
            $flags['action_type'] = $actionType;
            $flags['blocked_for_simplification'] = $blockedForSimplification;
            $flags['risk_floor'] = [
                'status' => $riskFloorReason === null ? 'clear' : 'blocked',
                'reason' => $riskFloorReason,
                'behavior_proof_source_count' => $behaviorProofSourceCount,
            ];
            $coverageByTarget[$name] = $flags;

            if (! $safe) {
                $allSafe = false;
                if (! $behaviorProof) {
                    $requiredNextTask[] = "prove_behavior_equivalence:{$name}";
                } elseif ($singleSourceBehaviorProof) {
                    $requiredNextTask[] = "diversify_behavior_proof_beyond_single_source:{$name}";
                }
                if (! $flags['rollback_proof']) {
                    $requiredNextTask[] = "compose_rollback_receipt:{$name}";
                }
            }
        }

        return [
            'coverage_by_target' => $coverageByTarget,
            'missing_proofs' => $missingProofs,
            'safe_for_consolidation' => $targets !== [] && $allSafe,
            'required_next_task' => $requiredNextTask,
        ];
    }
}
