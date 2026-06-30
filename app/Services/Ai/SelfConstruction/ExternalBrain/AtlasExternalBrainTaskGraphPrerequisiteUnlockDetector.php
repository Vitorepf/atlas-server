<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure detector. Identifies which queued tasks UNLOCK downstream capability
 * and should be implemented before their dependents or unrelated leaf tasks.
 *
 * A dependent edge exists between A → B when either:
 *   - B.depends_on contains A (declared dependency), OR
 *   - A.unlocks contains B (declared unlock)
 * Both directions are unioned so a single missing declaration on one side
 * doesn't hide a real edge.
 *
 * prerequisite_candidates: queued (not done) tasks with >= 1 dependent,
 * ranked by (count of high-impact dependents, total dependent count) desc —
 * a prerequisite that unblocks several high-priority downstream tasks ranks
 * ahead of one that only unblocks low-priority leaves.
 *
 * blocked_dependents: queued tasks whose depends_on still has at least one
 * not-done id, with the list of unfinished blockers.
 *
 * implementation_order_hints: prerequisite_candidates first (already
 * ranked), then remaining queued leaf tasks (zero dependents) in their
 * original order. done tasks are excluded.
 *
 * INPUT:
 *   tasks: list<{
 *     task_id:        string
 *     capability?:    string
 *     depends_on?:    list<string>
 *     unlocks?:       list<string>
 *     impact_class?:  string ('high'|'medium'|'low', default 'low')
 *     allowed_files?: list<string>
 *     status?:        string ('queued'|'blocked'|'done', default 'queued')
 *   }>
 *
 * Pure: no I/O, no side effects, never reorders a real queue itself.
 */
final class AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector
{
    public const SCHEMA = 'atlas.external_brain.task_graph_prerequisite_unlock_detector.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function detect(array $input): array
    {
        $rawTasks = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];

        $tasks = [];
        foreach ($rawTasks as $raw) {
            if (! is_array($raw) || ! isset($raw['task_id'])) {
                continue;
            }
            $id = (string) $raw['task_id'];
            $tasks[$id] = [
                'task_id' => $id,
                'capability' => (string) ($raw['capability'] ?? ''),
                'depends_on' => $this->toStringList($raw['depends_on'] ?? null),
                'unlocks' => $this->toStringList($raw['unlocks'] ?? null),
                'impact_class' => (string) ($raw['impact_class'] ?? 'low'),
                'allowed_files' => $this->toStringList($raw['allowed_files'] ?? null),
                'status' => (string) ($raw['status'] ?? 'queued'),
            ];
        }

        // Build the union dependent map: prerequisite_id => list of dependent task_ids.
        $dependents = [];
        foreach ($tasks as $id => $task) {
            foreach ($task['depends_on'] as $prereq) {
                $dependents[$prereq][$id] = true;
            }
            foreach ($task['unlocks'] as $dependent) {
                $dependents[$id][$dependent] = true;
            }
        }

        $downstreamUnlockCounts = [];
        $prerequisiteCandidates = [];
        $blockedDependents = [];

        foreach ($tasks as $id => $task) {
            $dependentIds = array_keys($dependents[$id] ?? []);
            $downstreamUnlockCounts[$id] = count($dependentIds);

            if ($task['status'] !== 'done' && $dependentIds !== []) {
                $highImpactDependentCount = 0;
                foreach ($dependentIds as $depId) {
                    if (($tasks[$depId]['impact_class'] ?? 'low') === 'high') {
                        $highImpactDependentCount++;
                    }
                }
                $prerequisiteCandidates[] = [
                    'task_id' => $id,
                    'capability' => $task['capability'],
                    'downstream_unlock_count' => count($dependentIds),
                    'high_impact_dependent_count' => $highImpactDependentCount,
                    'dependents' => $dependentIds,
                ];
            }

            if ($task['status'] !== 'done' && $task['depends_on'] !== []) {
                $unfinishedBlockers = array_values(array_filter(
                    $task['depends_on'],
                    static fn (string $prereq): bool => ($tasks[$prereq]['status'] ?? 'queued') !== 'done',
                ));
                if ($unfinishedBlockers !== []) {
                    $blockedDependents[] = [
                        'task_id' => $id,
                        'blocked_by' => $unfinishedBlockers,
                    ];
                }
            }
        }

        usort($prerequisiteCandidates, static function (array $a, array $b): int {
            return [$b['high_impact_dependent_count'], $b['downstream_unlock_count']]
                <=> [$a['high_impact_dependent_count'], $a['downstream_unlock_count']];
        });

        $prerequisiteIds = array_column($prerequisiteCandidates, 'task_id');
        $leafOrder = [];
        foreach ($tasks as $id => $task) {
            if ($task['status'] === 'done' || in_array($id, $prerequisiteIds, true)) {
                continue;
            }
            $leafOrder[] = $id;
        }

        return [
            'schema' => self::SCHEMA,
            'prerequisite_candidates' => $prerequisiteCandidates,
            'downstream_unlock_counts' => $downstreamUnlockCounts,
            'blocked_dependents' => $blockedDependents,
            'implementation_order_hints' => array_merge($prerequisiteIds, $leafOrder),
        ];
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== '')));
    }
}
