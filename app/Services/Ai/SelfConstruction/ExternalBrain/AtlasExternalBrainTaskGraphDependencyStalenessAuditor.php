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

        $staleEdges = [];
        $satisfiedDependencies = [];
        $brokenDependencies = [];
        $supersededDependents = [];
        $repairOrRetireRecommendations = [];

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
                $staleEdges[] = $edgeRow + ['reason' => 'dependency_status_unknown_dangling_reference'];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => 'repair',
                    'reason' => 'dependency_status_unknown_dangling_reference',
                ];

                continue;
            }

            if ($supersededBy !== '') {
                $supersededDependents[] = $edgeRow + ['superseded_by' => $supersededBy];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => 'repair',
                    'reason' => 'dependency_superseded_repoint_to_'.$supersededBy,
                ];

                continue;
            }

            if ($status === 'cancelled') {
                $brokenDependencies[] = $edgeRow + ['reason' => 'dependency_cancelled'];
                $repairOrRetireRecommendations[] = [
                    'task_id' => $taskId,
                    'action' => 'retire',
                    'reason' => 'dependency_cancelled_will_never_complete',
                ];

                continue;
            }

            if ($status === 'completed') {
                $delivered = $outcomeStatus === 'delivered' && $hasCapabilityEvidence;
                if ($delivered) {
                    $satisfiedDependencies[] = $edgeRow;
                } else {
                    $brokenDependencies[] = $edgeRow + ['reason' => 'completed_without_delivered_capability_evidence'];
                    $repairOrRetireRecommendations[] = [
                        'task_id' => $taskId,
                        'action' => 'repair',
                        'reason' => 'dependency_completed_without_capability_evidence',
                    ];
                }

                continue;
            }

            // Still legitimately waiting (queued/claimable/claimed/blocked/etc) — not stale.
        }

        return [
            'schema_version' => self::SCHEMA,
            'edge_count' => count($edges),
            'stale_edges' => $staleEdges,
            'satisfied_dependencies' => $satisfiedDependencies,
            'broken_dependencies' => $brokenDependencies,
            'superseded_dependents' => $supersededDependents,
            'repair_or_retire_recommendations' => $repairOrRetireRecommendations,
            'mutates_queue' => false,
        ];
    }
}
