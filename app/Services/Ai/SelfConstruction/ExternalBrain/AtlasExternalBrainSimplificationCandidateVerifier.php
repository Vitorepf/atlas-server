<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, facts-only gate that makes simplification (deletion, merge, replacement) first-class but SAFE.
 * A muscle proposing to remove or collapse code must prove the removal does not hide behavior loss:
 * zero live consumers (or an explicit migration plan for any it has), behavior coverage, test coverage
 * for merges, and a documented rollback path. Approval is the only path to a positive net_reduction_score
 * — never lets line-count alone justify deleting living code.
 *
 * Input candidate shape:
 *   {candidate_id, kind: 'deletion'|'merge'|'replacement', consumer_count:int, migration_plan?:string,
 *    consumer_paths?:list<string>, behavior_coverage:bool, behavior_equivalence_commands?:list<string>,
 *    test_coverage:bool, rollback_notes?:string, lines_deleted:int, preserved_behavior_tests?:list<string>}
 *
 * consumer_count and behavior_coverage are claims, not proof: consumer_count>0 requires the caller to
 * explicitly ENUMERATE those consumers via consumer_paths (a bare count alone never justifies a large
 * deletion), and behavior_coverage=true requires runnable behavior_equivalence_commands — a boolean
 * flag with no attached proof is treated as unproven.
 */
final class AtlasExternalBrainSimplificationCandidateVerifier
{
    public const SCHEMA = 'atlas.self_construction.external_brain.simplification_candidate_verifier.v1';

    public const KIND_MERGE = 'merge';

    public const BLOCKER_MISSING_WORKER_CONTINUITY_SAFETY_EVIDENCE = 'missing_worker_continuity_safety_evidence';

    public const BLOCKER_MISSING_LIVE_SHADOW_EQUIVALENCE_EVIDENCE = 'missing_live_shadow_equivalence_evidence';

    public const BLOCKER_MISSING_CONSUMER_ENUMERATION = 'missing_consumer_enumeration';

    public const BLOCKER_MISSING_BEHAVIOR_EQUIVALENCE_COMMANDS = 'missing_behavior_equivalence_commands';

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function verify(array $candidate): array
    {
        $candidateId = (string) ($candidate['candidate_id'] ?? '');
        $kind = (string) ($candidate['kind'] ?? '');
        $consumerCount = (int) ($candidate['consumer_count'] ?? 0);
        $migrationPlan = trim((string) ($candidate['migration_plan'] ?? ''));
        $behaviorCoverage = (bool) ($candidate['behavior_coverage'] ?? false);
        $testCoverage = (bool) ($candidate['test_coverage'] ?? false);
        $rollbackNotes = trim((string) ($candidate['rollback_notes'] ?? ''));
        $linesDeleted = (int) ($candidate['lines_deleted'] ?? 0);
        $preservedBehaviorTests = array_values(array_filter(array_map(
            'strval',
            (array) ($candidate['preserved_behavior_tests'] ?? []),
        ), static fn (string $t): bool => $t !== ''));
        $consumerPaths = array_values(array_filter(array_map(
            'strval',
            (array) ($candidate['consumer_paths'] ?? []),
        ), static fn (string $p): bool => $p !== ''));
        $behaviorEquivalenceCommands = array_values(array_filter(array_map(
            'strval',
            (array) ($candidate['behavior_equivalence_commands'] ?? []),
        ), static fn (string $c): bool => $c !== ''));

        $blockers = [];
        $requiredTests = [];

        if ($consumerCount > 0 && $migrationPlan === '') {
            $blockers[] = 'live_consumers_without_migration_plan';
        }

        // A bare consumer_count is a claim, not proof — the caller must name every consumer it
        // claims to have accounted for.
        if ($consumerCount > 0 && $consumerPaths === []) {
            $blockers[] = self::BLOCKER_MISSING_CONSUMER_ENUMERATION;
        }

        if (! $behaviorCoverage) {
            $blockers[] = 'missing_behavior_coverage';
        } elseif ($behaviorEquivalenceCommands === []) {
            // behavior_coverage=true is a boolean claim — it needs a runnable command attached,
            // or it is indistinguishable from an unverified assertion.
            $blockers[] = self::BLOCKER_MISSING_BEHAVIOR_EQUIVALENCE_COMMANDS;
        }

        if (! $testCoverage) {
            $blockers[] = 'missing_test_coverage';
            $requiredTests[] = 'add_test_coverage_for_'.($candidateId !== '' ? $candidateId : 'candidate');
        }

        if ($kind === self::KIND_MERGE) {
            if ($preservedBehaviorTests === []) {
                $blockers[] = 'merge_requires_preserved_behavior_tests';
                $requiredTests[] = 'preserved_behavior_tests_for_merge';
            } else {
                $requiredTests = array_merge($requiredTests, $preservedBehaviorTests);
            }
        }

        if ($rollbackNotes === '') {
            $blockers[] = 'missing_rollback_notes';
        }

        // Candidates touching queue-serving organs must prove worker-continuity safety —
        // line-count reduction alone (no matter how large) never justifies this on its own.
        $touchesQueueServingOrgan = (bool) ($candidate['touches_queue_serving_organ'] ?? false);
        $workerContinuitySafe = false;
        if ($touchesQueueServingOrgan) {
            $workerContinuitySafe = $this->workerContinuitySafe((array) ($candidate['worker_continuity_evidence'] ?? []));
            if (! $workerContinuitySafe) {
                $blockers[] = self::BLOCKER_MISSING_WORKER_CONTINUITY_SAFETY_EVIDENCE;
            }
        }

        // High-risk deletions/merges must prove behavioral equivalence was observed in a live
        // shadow run — line deletion + unit tests alone can miss production-only behavior.
        $highRisk = (bool) ($candidate['high_risk'] ?? false);
        $liveShadowEquivalenceEvidence = $candidate['live_shadow_equivalence_evidence'] ?? null;
        $hasLiveShadowEvidence = is_array($liveShadowEquivalenceEvidence)
            ? $liveShadowEquivalenceEvidence !== []
            : (bool) $liveShadowEquivalenceEvidence;
        if ($highRisk && ! $hasLiveShadowEvidence) {
            $blockers[] = self::BLOCKER_MISSING_LIVE_SHADOW_EQUIVALENCE_EVIDENCE;
        }

        $approved = $blockers === [];

        $rollbackRequirement = $rollbackNotes !== ''
            ? 'documented: '.$rollbackNotes
            : 'rollback_notes_required';

        $netReductionScore = $approved
            ? max(1, $linesDeleted - ($consumerCount * 5))
            : 0;

        $behaviorProofStatus = match (true) {
            ! $behaviorCoverage => 'missing',
            $kind === self::KIND_MERGE && $preservedBehaviorTests === [] => 'missing',
            default => 'proven',
        };

        $consumerMigrationStatus = match (true) {
            $consumerCount === 0 => 'no_consumers',
            $migrationPlan !== '' => 'migration_planned',
            default => 'unmigrated_consumers',
        };

        $recommendation = $approved ? 'proceed' : 'blocked';
        $proofSummary = [
            'candidate_id' => $candidateId,
            'kind' => $kind,
            'approved' => $approved,
            'blocker_count' => count($blockers),
            'behavior_proof_status' => $behaviorProofStatus,
            'consumer_migration_status' => $consumerMigrationStatus,
            'worker_continuity_safe' => $touchesQueueServingOrgan ? $workerContinuitySafe : null,
            'live_shadow_equivalence_evidence_present' => $highRisk ? $hasLiveShadowEvidence : null,
            'provider_safe' => true,
        ];

        return [
            'schema' => self::SCHEMA,
            'candidate_id' => $candidateId,
            'approved' => $approved,
            'recommendation' => $recommendation,
            'proof_summary' => $proofSummary,
            'net_reduction_score' => $netReductionScore,
            'blockers' => $blockers,
            'required_tests' => array_values(array_unique($requiredTests)),
            'consumer_paths' => $consumerPaths,
            'behavior_equivalence_commands' => $behaviorEquivalenceCommands,
            'rollback_requirement' => $rollbackRequirement,
            'behavior_proof_status' => $behaviorProofStatus,
            'consumer_migration_status' => $consumerMigrationStatus,
            'touches_queue_serving_organ' => $touchesQueueServingOrgan,
            'worker_continuity_safe' => $touchesQueueServingOrgan ? $workerContinuitySafe : null,
            'high_risk' => $highRisk,
            'live_shadow_equivalence_evidence_present' => $highRisk ? $hasLiveShadowEvidence : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence  may contain claimable_per_active_worker_before/_after
     *   and/or no_claimable_task_incidents_before/_after; both numeric pairs must be PRESENT and
     *   show no regression (never inferred from absence).
     */
    private function workerContinuitySafe(array $evidence): bool
    {
        $claimableBefore = $evidence['claimable_per_active_worker_before'] ?? null;
        $claimableAfter = $evidence['claimable_per_active_worker_after'] ?? null;
        $incidentsBefore = $evidence['no_claimable_task_incidents_before'] ?? null;
        $incidentsAfter = $evidence['no_claimable_task_incidents_after'] ?? null;

        if (! is_numeric($claimableBefore) || ! is_numeric($claimableAfter)) {
            return false;
        }
        if (! is_numeric($incidentsBefore) || ! is_numeric($incidentsAfter)) {
            return false;
        }

        $noClaimableDrop = (float) $claimableAfter >= (float) $claimableBefore;
        $noIncidentIncrease = (float) $incidentsAfter <= (float) $incidentsBefore;

        return $noClaimableDrop && $noIncidentIncrease;
    }
}
