<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner. Defines the proof gates required before deleting, merging or simplifying any
 * behavior-bearing Atlas code path, and downgrades unsafe requests to lock_first.
 *
 * REQUIRED PROOF GATES for a destructive action (delete | merge | simplify) on a
 * behavior-bearing target (default true so callers must opt a target out explicitly):
 *   parity_tests_available            — tests proving pre/post behavior parity
 *   consumer_impact_proof_available   — the blast radius on active consumers has been assessed
 *   rollback_evidence_available       — a proven rollback path exists
 * All three default to FALSE — unlike a downstream compression planner, this planner never
 * assumes proof exists; the caller must explicitly supply it.
 *
 * DOWNGRADE TO lock_first (destructive request only) when ANY of:
 *   - any proof gate above is missing
 *   - risk_level === 'high'
 *   - test_coverage === false (uncovered)
 *
 * Non-destructive requested actions (e.g. keep) and non-behavior-bearing targets bypass the
 * gate entirely and are echoed back as requested.
 *
 * Decisions are computed purely from target facts and explicit gate flags — never from a
 * static checklist.
 *
 * Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainBehaviorLockPlanner
{
    public const SCHEMA = 'atlas.external_brain.behavior_lock_planner.v1';

    public const ACTION_DELETE   = 'delete';
    public const ACTION_MERGE    = 'merge';
    public const ACTION_SIMPLIFY = 'simplify';

    public const DECISION_LOCK_FIRST = 'lock_first';

    private const DESTRUCTIVE_ACTIONS = [self::ACTION_DELETE, self::ACTION_MERGE, self::ACTION_SIMPLIFY];

    /**
     * @param  list<array<string,mixed>>  $targets  {target_id, requested_action, behavior_bearing?,
     *   parity_tests_available?, consumer_impact_proof_available?, rollback_evidence_available?,
     *   risk_level?, test_coverage?}
     * @return array{schema:string, decisions:list<array<string,mixed>>, proof_gate_matrix:list<array<string,mixed>>, lock_first_count:int}
     */
    public function plan(array $targets): array
    {
        $decisions = [];
        $proofGateMatrix = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $id               = (string) ($target['target_id'] ?? '');
            $requestedAction  = (string) ($target['requested_action'] ?? '');
            $behaviorBearing  = (bool) ($target['behavior_bearing'] ?? true);
            $parityTests      = (bool) ($target['parity_tests_available'] ?? false);
            $consumerImpact   = (bool) ($target['consumer_impact_proof_available'] ?? false);
            $rollbackEvidence = (bool) ($target['rollback_evidence_available'] ?? false);
            $riskLevel        = (string) ($target['risk_level'] ?? 'medium');
            $testCoverage     = (bool) ($target['test_coverage'] ?? false);

            $allGatesPassed = $parityTests && $consumerImpact && $rollbackEvidence;
            $isDestructiveRequest = $behaviorBearing && in_array($requestedAction, self::DESTRUCTIVE_ACTIONS, true);

            $blockers = [];
            if ($isDestructiveRequest) {
                if (! $parityTests) {
                    $blockers[] = 'missing_parity_tests';
                }
                if (! $consumerImpact) {
                    $blockers[] = 'missing_consumer_impact_proof';
                }
                if (! $rollbackEvidence) {
                    $blockers[] = 'missing_rollback_evidence';
                }
                if ($riskLevel === 'high') {
                    $blockers[] = 'high_risk_target';
                }
                if (! $testCoverage) {
                    $blockers[] = 'uncovered_target';
                }
            }

            $decision = ($isDestructiveRequest && $blockers !== []) ? self::DECISION_LOCK_FIRST : $requestedAction;

            $proofGateMatrix[] = [
                'target_id'                        => $id,
                'parity_tests_available'            => $parityTests,
                'consumer_impact_proof_available'   => $consumerImpact,
                'rollback_evidence_available'        => $rollbackEvidence,
                'all_gates_passed'                  => $allGatesPassed,
            ];

            $decisions[] = [
                'target_id'        => $id,
                'requested_action' => $requestedAction,
                'decision'         => $decision,
                'blockers'         => $blockers,
                'risk_level'       => $riskLevel,
                'test_coverage'    => $testCoverage,
            ];
        }

        $lockFirstCount = count(array_filter(
            $decisions,
            static fn (array $d): bool => $d['decision'] === self::DECISION_LOCK_FIRST,
        ));

        return [
            'schema'            => self::SCHEMA,
            'decisions'         => $decisions,
            'proof_gate_matrix' => $proofGateMatrix,
            'lock_first_count'  => $lockFirstCount,
        ];
    }
}
