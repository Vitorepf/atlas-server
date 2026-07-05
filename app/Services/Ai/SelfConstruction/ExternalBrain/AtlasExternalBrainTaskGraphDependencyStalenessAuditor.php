<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure dependency-staleness auditor for the task graph. After muscles
 * implement other tasks, a dependency edge can silently rot: the target
 * task vanished, was superseded, claims "completed" without real capability
 * evidence, or was cancelled outright. This distinguishes those rotten
 * edges from a dependent that is still legitimately waiting.
 *
 * Classification per edge (task_id depends_on depends_on_task_id):
 *   stale_edges            — depends_on_task_id has no known status (dangling reference).
 *   superseded_dependents   — the dependency was superseded by another task.
 *   broken_dependencies     — dependency is cancelled, OR claims completed
 *                             without delivered+evidenced outcome.
 *   satisfied_dependencies  — dependency completed with a delivered outcome
 *                             AND capability evidence present.
 *   (everything else)       — still-valid waiting dependency, not reported
 *                             as stale — queued/claimed/blocked dependencies
 *                             are legitimate, not rot.
 *
 * Pure: no I/O, never mutates queue state.
 */
final class AtlasExternalBrainTaskGraphDependencyStalenessAuditor
{
    public const SCHEMA = 'atlas.external_brain.task_graph_dependency_staleness_auditor.v1';

    /** A still-waiting dependency older than this many days is flagged stale, not just legitimate. */
    private const STALE_DEPENDENCY_AGE_DAYS = 30;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function audit(array $facts): array
    {
        $edges = array_values((array) ($facts['edges'] ?? []));
        $statuses = (array) ($facts['statuses'] ?? []);
        $completionOutcomes = (array) ($facts['completion_outcomes'] ?? []);
        $supersededTargets = (array) ($facts['superseded_targets'] ?? []);
        // AC2: a dependency can be satisfied by capability implemented elsewhere without ever
        // being formally renamed via superseded_targets — opt-in via this separate fact map.
        $capabilityAlreadyImplemented = (array) ($facts['capability_already_implemented'] ?? []);
        // AC3: age-based staleness for a dependency still in a legitimate waiting status —
        // opt-in via this fact map, so callers who don't supply it are unaffected.
        $dependencyAgeDays = (array) ($facts['dependency_age_days'] ?? []);

        $staleEdges = [];
        $satisfiedDependencies = [];
        $brokenDependencies = [];
        $supersededDependents = [];
        $repairOrRetireRecommendations = [];
        $staleTaskIds = [];
        $dependencyBlockers = [];
        $recommendedChainAction = [];
        $malformedEdges = [];

        $flag = function (string $taskId, string $dependsOn, string $rescopePlan, string $chainAction) use (&$staleTaskIds, &$dependencyBlockers, &$recommendedChainAction): void {
            $staleTaskIds[$taskId] = true;
            $dependencyBlockers[$taskId][] = $dependsOn;
            $recommendedChainAction[$taskId] = $chainAction;
        };

        foreach ($edges as $edge) {
            $edge = (array) $edge;
            $taskId = (string) ($edge['task_id'] ?? '');
            $dependsOn = (string) ($edge['depends_on_task_id'] ?? '');
            if ($taskId === '' || $dependsOn === '') {
                continue;
            }

            $supersededBy = trim((string) ($supersededTargets[$dependsOn] ?? ''));
            $status = isset($statuses[$dependsOn]) ? strtolower(trim((string) $statuses[$dependsOn])) : '';
            $outcome = (array) ($completionOutcomes[$dependsOn] ?? []);
            $outcomeStatus = strtolower(trim((string) ($outcome['outcome'] ?? '')));
            $hasCapabilityEvidence = (bool) ($outcome['capability_evidence_present'] ?? false);

            $edgeRow = ['task_id' => $taskId, 'depends_on_task_id' => $dependsOn];

            if ($status === '') {
                $staleEdges[] = $edgeRow + [
                    'reason' => 'dependency_status_unknown_dangling_reference',
                    'rescope_plan' => "repoint or drop {$taskId}'s dependency on {$dependsOn}: no known status for a dangling reference",
                    'rescope_patch' => ['remove_depends_on' => $dependsOn],
                ];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => 'repair',
                    'reason' => 'dependency_status_unknown_dangling_reference',
                ];
                $flag($taskId, $dependsOn, $staleEdges[array_key_last($staleEdges)]['rescope_plan'], 'rescope');

                continue;
            }

            if ($supersededBy !== '') {
                $rescopePlan = "repoint {$taskId}'s dependency from {$dependsOn} to its replacement {$supersededBy}";
                $supersededDependents[] = $edgeRow + ['superseded_by' => $supersededBy, 'reason' => 'formally_superseded', 'rescope_plan' => $rescopePlan, 'rescope_patch' => ['replace_depends_on' => $dependsOn, 'replacement_id' => $supersededBy]];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => 'repair',
                    'reason' => 'dependency_superseded_repoint_to_'.$supersededBy,
                ];
                $flag($taskId, $dependsOn, $rescopePlan, 'rescope');

                continue;
            }

            // AC2: capability already implemented elsewhere — the dependency was never formally
            // renamed, but the thing it was blocking on already exists, so it's superseded in
            // effect, not merely satisfied by the literal dependsOn task completing.
            $implementedBy = trim((string) ($capabilityAlreadyImplemented[$dependsOn] ?? ''));
            if ($implementedBy !== '') {
                $rescopePlan = "repoint {$taskId}'s dependency from {$dependsOn} to {$implementedBy}, which already implements the capability it was waiting on";
                $supersededDependents[] = $edgeRow + ['superseded_by' => $implementedBy, 'reason' => 'capability_already_implemented_elsewhere', 'rescope_plan' => $rescopePlan, 'rescope_patch' => ['replace_depends_on' => $dependsOn, 'replacement_id' => $implementedBy]];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => 'repair',
                    'reason' => 'dependency_capability_already_implemented_by_'.$implementedBy,
                ];
                $flag($taskId, $dependsOn, $rescopePlan, 'rescope');

                continue;
            }

            if (in_array($status, ['cancelled', 'quarantined'], true)) {
                $reason = $status === 'quarantined' ? 'dependency_quarantined' : 'dependency_cancelled';
                $rescopePlan = $status === 'quarantined'
                    ? "rescope {$taskId} to drop or replace its quarantined dependency {$dependsOn} pending review"
                    : "retire {$taskId}: its dependency {$dependsOn} is cancelled and will never complete";
                $brokenDependencies[] = $edgeRow + ['reason' => $reason, 'rescope_plan' => $rescopePlan, 'rescope_patch' => ['remove_depends_on' => $dependsOn]];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => $status === 'quarantined' ? 'rescope' : 'retire',
                    'reason' => $status === 'quarantined' ? 'dependency_quarantined_pending_review' : 'dependency_cancelled_will_never_complete',
                ];
                $flag($taskId, $dependsOn, $rescopePlan, $status === 'quarantined' ? 'rescope' : 'retire');

                continue;
            }

            if ($status === 'completed') {
                $delivered = $outcomeStatus === 'delivered' && $hasCapabilityEvidence;
                if ($delivered) {
                    $satisfiedDependencies[] = $edgeRow;
                } else {
                    $rescopePlan = "rescope {$taskId}: dependency {$dependsOn} claims completed but lacks delivered capability evidence — request proof or reopen";
                    $brokenDependencies[] = $edgeRow + ['reason' => 'completed_without_delivered_capability_evidence', 'rescope_plan' => $rescopePlan, 'rescope_patch' => ['remove_depends_on' => $dependsOn]];
                    $repairOrRetireRecommendations[] = [
                        'task_id' => $taskId,
                        'action' => 'repair',
                        'reason' => 'dependency_completed_without_capability_evidence',
                    ];
                    $flag($taskId, $dependsOn, $rescopePlan, 'rescope');
                }

                continue;
            }

            // AC3: still in a legitimate waiting status, but if it's been waiting for a long
            // time, that itself is a staleness signal worth resequencing around — flagged with
            // the literal reason=stale_dependency (distinct from a dangling/unknown reference).
            $ageDays = max(0, (int) ($dependencyAgeDays[$dependsOn] ?? 0));
            if ($ageDays > self::STALE_DEPENDENCY_AGE_DAYS) {
                $rescopePlan = "resequence {$taskId}: dependency {$dependsOn} has been waiting {$ageDays} days — verify it is still required, or drop/replace it";
                $staleEdges[] = $edgeRow + ['reason' => 'stale_dependency', 'age_days' => $ageDays, 'rescope_plan' => $rescopePlan, 'rescope_patch' => ['remove_depends_on' => $dependsOn]];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => 'resequence',
                    'reason' => 'stale_dependency',
                ];
                $flag($taskId, $dependsOn, $rescopePlan, 'resequence');

                continue;
            }

            // Still legitimately waiting (queued/claimable/claimed/blocked/etc) — not stale.
        }

        // AC4: a concrete resequencing plan for the task graph — one entry per flagged task,
        // naming exactly which dependency blocks it and what to do about it.
        $resequenceActions = [];
        foreach (array_keys($staleTaskIds) as $taskId) {
            $resequenceActions[] = [
                'task_id' => $taskId,
                'action' => $recommendedChainAction[$taskId] ?? 'rescope',
                'blocked_by' => $dependencyBlockers[$taskId] ?? [],
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'edge_count' => count($edges),
            'stale_edges' => $staleEdges,
            'satisfied_dependencies' => $satisfiedDependencies,
            'broken_dependencies' => $brokenDependencies,
            'superseded_dependents' => $supersededDependents,
            'repair_or_retire_recommendations' => $repairOrRetireRecommendations,
            'stale_task_ids' => array_values(array_keys($staleTaskIds)),
            'dependency_blockers' => $dependencyBlockers,
            'recommended_chain_action' => array_intersect_key($recommendedChainAction, $staleTaskIds),
            'resequence_actions' => $resequenceActions,
            'mutates_queue' => false,
        ];
    }
}
