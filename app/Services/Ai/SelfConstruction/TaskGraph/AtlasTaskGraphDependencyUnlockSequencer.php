<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Pure, facts-only sequencer for blocked/dependent task chains. Orders tasks by UNLOCK VALUE — how many
 * downstream tasks a prerequisite would make claimable — not just by risk or arrival order, so the brain
 * stops emitting isolated leaf tasks when one prerequisite would unlock a whole chain. Refuses to produce
 * any ordering over a circular or missing-prerequisite graph; reports the exact diagnostic instead.
 *
 * Input task shape: {task_packet_id, depends_on?:list<string>, risk?:int}  (lower risk wins ties)
 */
final class AtlasTaskGraphDependencyUnlockSequencer
{
    public const SCHEMA = 'atlas.self_construction.task_graph.dependency_unlock_sequencer.v1';

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public function sequence(array $tasks): array
    {
        $byId = [];
        foreach ($tasks as $task) {
            $id = (string) ($task['task_packet_id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $task;
            }
        }

        $blockedReasons = [];
        $missingIds = [];
        foreach ($byId as $id => $task) {
            foreach ($this->dependsOn($task) as $dep) {
                if (! isset($byId[$dep])) {
                    $blockedReasons[] = ['task_packet_id' => $id, 'reason' => 'missing_prerequisite', 'missing' => $dep];
                    $missingIds[$id] = true;
                }
            }
        }

        $cyclePath = $this->detectCycle($byId);
        if ($cyclePath !== null) {
            return [
                'schema' => self::SCHEMA,
                'cycle_detected' => true,
                'cycle_path' => $cyclePath,
                'ordered_task_ids' => [],
                'unlock_counts' => [],
                'blocked_reasons' => $this->sortBlockedReasons($blockedReasons),
                'next_unlock_target' => null,
            ];
        }

        $dependents = $this->dependentsGraph($byId);
        $unlockCounts = $this->unlockCounts($byId, $dependents);

        $unsequenceable = [];
        foreach (array_keys($byId) as $id) {
            $this->resolveUnsequenceable($id, $byId, $missingIds, $unsequenceable);
        }
        foreach ($unsequenceable as $id => $bad) {
            if ($bad && ! isset($missingIds[$id])) {
                $blockedReasons[] = ['task_packet_id' => $id, 'reason' => 'blocked_by_unsequenceable_prerequisite'];
            }
        }

        $ordered = $this->greedyUnlockOrder($byId, $unsequenceable, $unlockCounts);

        ksort($unlockCounts);

        return [
            'schema' => self::SCHEMA,
            'cycle_detected' => false,
            'cycle_path' => [],
            'ordered_task_ids' => $ordered,
            'unlock_counts' => $unlockCounts,
            'blocked_reasons' => $this->sortBlockedReasons($blockedReasons),
            'next_unlock_target' => $ordered[0] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return list<string>
     */
    private function dependsOn(array $task): array
    {
        return array_values(array_filter(array_map('strval', (array) ($task['depends_on'] ?? [])), static fn (string $d): bool => $d !== ''));
    }

    /**
     * DFS cycle detection over the depends_on graph (missing prerequisites are skipped — handled separately).
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @return list<string>|null
     */
    private function detectCycle(array $byId): ?array
    {
        $color = []; // 0 = unvisited (absent), 1 = in-progress, 2 = done
        foreach (array_keys($byId) as $id) {
            if (($color[$id] ?? 0) === 0) {
                $path = [];
                $cycle = $this->cycleDfs($id, $byId, $color, $path);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, int>  $color
     * @param  list<string>  $path
     * @return list<string>|null
     */
    private function cycleDfs(string $id, array $byId, array &$color, array $path): ?array
    {
        $color[$id] = 1;
        $path[] = $id;

        foreach ($this->dependsOn($byId[$id]) as $dep) {
            if (! isset($byId[$dep])) {
                continue;
            }
            if (($color[$dep] ?? 0) === 1) {
                $idx = array_search($dep, $path, true);

                return array_merge(array_slice($path, $idx === false ? 0 : $idx), [$dep]);
            }
            if (($color[$dep] ?? 0) === 0) {
                $cycle = $this->cycleDfs($dep, $byId, $color, $path);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        $color[$id] = 2;

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     * @return array<string, list<string>>
     */
    private function dependentsGraph(array $byId): array
    {
        $dependents = [];
        foreach ($byId as $id => $task) {
            foreach ($this->dependsOn($task) as $dep) {
                if (isset($byId[$dep])) {
                    $dependents[$dep][] = $id;
                }
            }
        }

        return $dependents;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, list<string>>  $dependents
     * @return array<string, int>
     */
    private function unlockCounts(array $byId, array $dependents): array
    {
        $counts = [];
        foreach (array_keys($byId) as $id) {
            $seen = [];
            $stack = $dependents[$id] ?? [];
            while ($stack !== []) {
                $cur = array_pop($stack);
                if (isset($seen[$cur])) {
                    continue;
                }
                $seen[$cur] = true;
                foreach ($dependents[$cur] ?? [] as $next) {
                    $stack[] = $next;
                }
            }
            $counts[$id] = count($seen);
        }

        return $counts;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, bool>  $missingIds
     * @param  array<string, bool>  $memo
     */
    private function resolveUnsequenceable(string $id, array $byId, array $missingIds, array &$memo): bool
    {
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        if (isset($missingIds[$id])) {
            $memo[$id] = true;

            return true;
        }
        $memo[$id] = false; // guard against re-entry; graph is acyclic so this is safe
        foreach ($this->dependsOn($byId[$id]) as $dep) {
            if (! isset($byId[$dep]) || $this->resolveUnsequenceable($dep, $byId, $missingIds, $memo)) {
                $memo[$id] = true;

                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, bool>  $unsequenceable
     * @param  array<string, int>  $unlockCounts
     * @return list<string>
     */
    private function greedyUnlockOrder(array $byId, array $unsequenceable, array $unlockCounts): array
    {
        $remaining = array_values(array_filter(array_keys($byId), static fn (string $id): bool => ! ($unsequenceable[$id] ?? false)));
        $completed = [];
        $ordered = [];

        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, function (string $id) use ($byId, $completed): bool {
                foreach ($this->dependsOn($byId[$id]) as $dep) {
                    if (! isset($completed[$dep])) {
                        return false;
                    }
                }

                return true;
            }));
            if ($ready === []) {
                break; // defensive: should not happen on an acyclic, fully-resolved graph
            }
            usort($ready, static fn (string $a, string $b): int => ($unlockCounts[$b] ?? 0) <=> ($unlockCounts[$a] ?? 0)
                ?: ((int) ($byId[$a]['risk'] ?? 0)) <=> ((int) ($byId[$b]['risk'] ?? 0))
                ?: strcmp($a, $b));

            $next = $ready[0];
            $ordered[] = $next;
            $completed[$next] = true;
            $remaining = array_values(array_diff($remaining, [$next]));
        }

        return $ordered;
    }

    /**
     * @param  list<array<string, mixed>>  $blockedReasons
     * @return list<array<string, mixed>>
     */
    private function sortBlockedReasons(array $blockedReasons): array
    {
        usort($blockedReasons, static fn (array $a, array $b): int => strcmp((string) $a['task_packet_id'], (string) $b['task_packet_id'])
            ?: strcmp((string) $a['reason'], (string) $b['reason']));

        return $blockedReasons;
    }
}
