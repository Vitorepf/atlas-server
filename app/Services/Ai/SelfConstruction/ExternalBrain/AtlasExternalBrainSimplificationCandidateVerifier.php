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
 *    behavior_coverage:bool, test_coverage:bool, rollback_notes?:string, lines_deleted:int,
 *    preserved_behavior_tests?:list<string>}
 */
final class AtlasExternalBrainSimplificationCandidateVerifier
{
    public const SCHEMA = 'atlas.self_construction.external_brain.simplification_candidate_verifier.v1';

    public const KIND_MERGE = 'merge';

    public const BLOCKER_MISSING_WORKER_CONTINUITY_SAFETY_EVIDENCE = 'missing_worker_continuity_safety_evidence';

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

        $blockers = [];
        $requiredTests = [];

        if ($consumerCount > 0 && $migrationPlan === '') {
            $blockers[] = 'live_consumers_without_migration_plan';
        }

        if (! $behaviorCoverage) {
            $blockers[] = 'missing_behavior_coverage';
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

        return [
            'schema' => self::SCHEMA,
            'candidate_id' => $candidateId,
            'approved' => $approved,
            'net_reduction_score' => $netReductionScore,
            'blockers' => $blockers,
            'required_tests' => array_values(array_unique($requiredTests)),
            'rollback_requirement' => $rollbackRequirement,
            'behavior_proof_status' => $behaviorProofStatus,
            'consumer_migration_status' => $consumerMigrationStatus,
            'touches_queue_serving_organ' => $touchesQueueServingOrgan,
            'worker_continuity_safe' => $touchesQueueServingOrgan ? $workerContinuitySafe : null,
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
