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
 *     task_id:               string
 *     capability?:           string
 *     depends_on?:           list<string>
 *     unlocks?:              list<string>
 *     requires_capabilities? list<string>  (AC2/AC3 new: services/gates/CLI names the objective needs)
 *     task_family?:          string        (AC4 new: groups dependents for unlock_notes)
 *     impact_class?:  string ('high'|'medium'|'low', default 'low')
 *     allowed_files?: list<string>
 *     status?:        string ('queued'|'blocked'|'done', default 'queued')
 *   }>
 *
 * requires_capabilities (AC2/AC3 new): resolved purely against the OTHER tasks in this same input
 * batch (no filesystem/codebase lookup — the detector stays pure). For each required capability
 * name, if another task's `capability` field matches it, a depends_on edge onto that task is
 * merged in (deduped, so an already-declared depends_on is never duplicated). If no task in the
 * batch provides it, a synthetic `missing_capability:{name}` blocker id is merged in instead —
 * the referencing task then correctly surfaces in blocked_dependents (a missing id defaults to
 * "not done") instead of being treated as a standalone, ready-to-release leaf. Every resolution
 * (resolved or missing) is also reported verbatim in `inferred_prerequisites`.
 *
 * unlock_notes (AC4 new): each prerequisite_candidates entry names, per task_family among its
 * dependents (falling back to the individual task_id when no family is given), which family
 * becomes safe to release once this prerequisite lands.
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
                'requires_capabilities' => $this->toStringList($raw['requires_capabilities'] ?? null),
                'task_family' => (string) ($raw['task_family'] ?? ''),
                'impact_class' => (string) ($raw['impact_class'] ?? 'low'),
                'allowed_files' => $this->toStringList($raw['allowed_files'] ?? null),
                'status' => (string) ($raw['status'] ?? 'queued'),
            ];
        }

        // AC2/AC3: resolve requires_capabilities against other tasks' `capability` field. A match
        // merges a depends_on edge onto the providing task; no match merges a synthetic
        // missing_capability blocker so the referencing task is never treated as standalone.
        $capabilityProviders = [];
        foreach ($tasks as $id => $task) {
            if ($task['capability'] !== '' && ! isset($capabilityProviders[$task['capability']])) {
                $capabilityProviders[$task['capability']] = $id;
            }
        }

        $inferredPrerequisites = [];
        foreach ($tasks as $id => $task) {
            foreach ($task['requires_capabilities'] as $cap) {
                $resolvedId = $capabilityProviders[$cap] ?? null;
                if ($resolvedId !== null && $resolvedId !== $id) {
                    $tasks[$id]['depends_on'][] = $resolvedId;
                    $inferredPrerequisites[] = [
                        'task_id' => $id, 'requires_capability' => $cap,
                        'resolved_task_id' => $resolvedId, 'status' => 'resolved',
                    ];
                } else {
                    $tasks[$id]['depends_on'][] = 'missing_capability:'.$cap;
                    $inferredPrerequisites[] = [
                        'task_id' => $id, 'requires_capability' => $cap,
                        'resolved_task_id' => null, 'status' => 'missing',
                    ];
                }
            }
            $tasks[$id]['depends_on'] = array_values(array_unique($tasks[$id]['depends_on']));
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
                    'unlock_notes' => $this->unlockNotes($id, $task['capability'], $dependentIds, $tasks),
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
            'inferred_prerequisites' => $inferredPrerequisites,
        ];
    }

    /**
     * @param  list<string>  $dependentIds
     * @param  array<string,array<string,mixed>>  $tasks
     * @return list<string>
     */
    private function unlockNotes(string $id, string $capability, array $dependentIds, array $tasks): array
    {
        $familyGroups = [];
        foreach ($dependentIds as $depId) {
            $family = (string) ($tasks[$depId]['task_family'] ?? '');
            $familyGroups[$family !== '' ? $family : $depId][] = $depId;
        }

        $label = $capability !== '' ? $capability : $id;
        $notes = [];
        foreach ($familyGroups as $familyKey => $ids) {
            $notes[] = sprintf('landing %s unlocks task family "%s" (%s)', $label, $familyKey, implode(', ', $ids));
        }

        return $notes;
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== '')));
    }
}
