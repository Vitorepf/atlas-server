<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Parallel;

use RuntimeException;

/**
 * Pure deterministic planner that reads a normalized sub-step DAG and emits parallelizable waves.
 *
 * Each step: `{step_id: string, allowed_files: list<string>, depends_on: list<string>}`.
 *
 * Wave rule:
 *   - All declared dependencies are in EARLIER waves.
 *   - Steps in the same wave have pairwise-disjoint allowed_files write sets.
 *
 * Refusals (typed exceptions): cycle detection, unknown dependency, empty step row.
 * Empty step list ⇒ `{groups: [], rationale: []}` byte-identical no-op.
 */
final class AtlasAaelParallelStepScheduler
{
    public const SCHEMA = 'atlas.aael.parallel_step_scheduler.v1';

    /**
     * @param  list<array<string,mixed>>  $steps
     * @return array<string,mixed>
     */
    public function plan(array $steps): array
    {
        if ($steps === []) {
            return ['schema' => self::SCHEMA, 'groups' => [], 'rationale' => []];
        }

        $byId = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                throw new ParallelStepSchedulerInvalidStepException('step_row_not_array');
            }
            $id = (string) ($step['step_id'] ?? '');
            if ($id === '') {
                throw new ParallelStepSchedulerInvalidStepException('step_id_empty');
            }
            if (isset($byId[$id])) {
                throw new ParallelStepSchedulerInvalidStepException('duplicate_step_id:'.$id);
            }
            $byId[$id] = [
                'step_id' => $id,
                'allowed_files' => array_values(array_map('strval', (array) ($step['allowed_files'] ?? []))),
                'depends_on' => array_values(array_map('strval', (array) ($step['depends_on'] ?? []))),
            ];
        }

        // Unknown dependency check.
        foreach ($byId as $step) {
            foreach ($step['depends_on'] as $dep) {
                if (! isset($byId[$dep])) {
                    throw new ParallelStepSchedulerUnknownDependencyException('unknown_dependency:'.$dep.' from '.$step['step_id']);
                }
            }
        }

        // Cycle detection via DFS.
        $this->detectCycle($byId);

        $remaining = $byId;
        $completed = [];
        $groups = [];
        $rationale = [];

        while ($remaining !== []) {
            $eligible = [];
            foreach ($remaining as $id => $step) {
                $allDepsDone = true;
                foreach ($step['depends_on'] as $dep) {
                    if (! isset($completed[$dep])) {
                        $allDepsDone = false;
                        break;
                    }
                }
                if ($allDepsDone) {
                    $eligible[$id] = $step;
                }
            }

            if ($eligible === []) {
                throw new ParallelStepSchedulerDagCycleException('unsatisfiable_dependencies: '.implode(',', array_keys($remaining)));
            }

            // Pack into one wave with disjoint write-sets; sort by id for determinism.
            ksort($eligible, SORT_STRING);
            $wave = [];
            $waveFiles = [];
            foreach ($eligible as $id => $step) {
                $overlap = array_intersect($step['allowed_files'], $waveFiles);
                if ($overlap !== []) {
                    $rationale[$id] = [
                        'reason' => 'write_set_overlap',
                        'blocking_files' => array_values($overlap),
                        'deferred_to_next_wave_after' => array_keys($wave),
                    ];

                    continue;
                }
                $wave[$id] = $step;
                $waveFiles = array_values(array_unique(array_merge($waveFiles, $step['allowed_files'])));
                if (! isset($rationale[$id])) {
                    $rationale[$id] = ['reason' => 'grouped_in_wave', 'wave_index' => count($groups)];
                }
            }

            if ($wave === []) {
                // No progress possible — impossible if eligible was non-empty unless a step's
                // own write-set conflicts with itself. Defensive.
                throw new ParallelStepSchedulerDagCycleException('no_progress_wave_packing');
            }

            $groupIds = array_keys($wave);
            sort($groupIds, SORT_STRING);
            $groups[] = $groupIds;
            foreach ($groupIds as $id) {
                $completed[$id] = true;
                unset($remaining[$id]);
            }
        }

        ksort($rationale, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'groups' => $groups,
            'rationale' => $rationale,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $byId
     */
    private function detectCycle(array $byId): void
    {
        $WHITE = 0;
        $GRAY = 1;
        $BLACK = 2;
        $color = array_fill_keys(array_keys($byId), $WHITE);
        $path = [];

        $visit = function (string $node) use (&$visit, &$color, $byId, $WHITE, $GRAY, $BLACK, &$path): void {
            if ($color[$node] === $GRAY) {
                $idx = array_search($node, $path, true);
                $cycle = array_slice($path, $idx === false ? 0 : $idx);
                $cycle[] = $node;
                throw new ParallelStepSchedulerDagCycleException('cycle:'.implode('->', $cycle));
            }
            if ($color[$node] === $BLACK) {
                return;
            }
            $color[$node] = $GRAY;
            $path[] = $node;
            foreach ((array) ($byId[$node]['depends_on'] ?? []) as $dep) {
                $visit((string) $dep);
            }
            array_pop($path);
            $color[$node] = $BLACK;
        };

        foreach (array_keys($byId) as $id) {
            $visit($id);
        }
    }
}

final class ParallelStepSchedulerDagCycleException extends RuntimeException {}

final class ParallelStepSchedulerUnknownDependencyException extends RuntimeException {}

final class ParallelStepSchedulerInvalidStepException extends RuntimeException {}
