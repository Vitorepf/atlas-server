<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Pure compiler. Turns gap index + calibrated impact + queue forecast into a
 * phased roadmap (near_term / mid_term / long_term).
 *
 * Algorithm:
 *   1. Dependency-level ordering: BFS over depends_on produces topological levels;
 *      within each level gaps are sorted by calibrated_impact descending.
 *   2. Near-term capacity: worker_capacity.near_term, halved when
 *      queue_forecast.current_pressure > PRESSURE_CAP (fail-safe default 0.75).
 *   3. Phase assignment in level order: near_term fills first, then mid_term, rest → long_term.
 *   4. Compression candidates noted in each phase they land in.
 *   5. Chain-completions: any scheduled gap that has at least one dependency
 *      (it closes a prerequisite link).
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasStrategyCouncilLongHorizonRoadmapCompiler
{
    public const SCHEMA = 'atlas.strategy_council.long_horizon_roadmap.v1';

    private const PRESSURE_CAP = 0.75;

    private const PRESSURE_REDUCTION_FACTOR = 0.6;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function compile(array $facts): array
    {
        $gapIndex       = is_array($facts['gap_index'] ?? null) ? $facts['gap_index'] : [];
        $impactMap      = is_array($facts['calibrated_impact'] ?? null) ? $facts['calibrated_impact'] : [];
        $queueForecast  = is_array($facts['queue_forecast'] ?? null) ? $facts['queue_forecast'] : [];
        $workerCapacity = is_array($facts['worker_capacity'] ?? null) ? $facts['worker_capacity'] : [];
        $compressionIds = is_array($facts['compression_candidates'] ?? null) ? array_map('strval', $facts['compression_candidates']) : [];

        $nearTermBase    = max(1, (int) ($workerCapacity['near_term'] ?? 5));
        $midTermBase     = max(1, (int) ($workerCapacity['mid_term'] ?? 8));
        $currentPressure = (float) ($queueForecast['current_pressure'] ?? 0.0);

        $pressureCapped = $currentPressure > self::PRESSURE_CAP;
        $nearTermCap    = $pressureCapped ? max(1, (int) floor($nearTermBase * self::PRESSURE_REDUCTION_FACTOR)) : $nearTermBase;

        [$ordered, $unscheduledIds, $levels] = $this->orderByDependencies($gapIndex, $impactMap);

        [$nearIds, $midIds, $longIds] = $this->assignPhases($ordered, $nearTermCap, $midTermBase);

        [$taskableSlices, $notQueueReadyIds] = $this->compileTaskableSlices($gapIndex);

        $phases = [
            'near_term' => $this->phaseEnvelope($nearIds, $pressureCapped, $gapIndex, $compressionIds),
            'mid_term'  => $this->phaseEnvelope($midIds, false, $gapIndex, $compressionIds),
            'long_term' => $this->phaseEnvelope($longIds, false, $gapIndex, $compressionIds),
        ];

        return [
            'schema_version' => self::SCHEMA,
            'phases' => $phases,
            'prerequisite_chains_respected' => $unscheduledIds === [],
            'total_gaps_scheduled'          => count($ordered),
            'unscheduled_gap_ids'           => $unscheduledIds,
            'dependency_blockers'           => $unscheduledIds,
            'near_term_capacity_used'       => $nearTermCap,
            'queue_pressure_capped'         => $pressureCapped,
            'taskable_slices'               => $taskableSlices,
            'not_queue_ready_gap_ids'       => $notQueueReadyIds,
            'capability_arcs'               => $this->compileCapabilityArcs($ordered, $levels),
            'dependency_chains'             => $this->compileDependencyChains($gapIndex),
            'proof_milestones'              => $this->compileProofMilestones($taskableSlices),
            'simplification_waves'         => $this->compileSimplificationWaves($phases),
            'stop_go_checkpoints'           => $this->compileStopGoCheckpoints($unscheduledIds, $pressureCapped, $notQueueReadyIds),
            'risk_notes'                    => $this->compileRiskNotes($unscheduledIds, $pressureCapped, $notQueueReadyIds),
        ];
    }

    /**
     * Groups scheduled gaps by dependency level into named capability arcs — each arc is
     * a wave of work that becomes ready at the same point in the dependency graph.
     *
     * @param  list<array<string,mixed>>  $ordered
     * @param  array<string,int>          $levels
     * @return list<array<string,mixed>>
     */
    private function compileCapabilityArcs(array $ordered, array $levels): array
    {
        $byLevel = [];
        foreach ($ordered as $gap) {
            $id = (string) ($gap['id'] ?? '');
            $byLevel[$levels[$id] ?? 0][] = $id;
        }
        ksort($byLevel);

        $arcs = [];
        foreach ($byLevel as $level => $ids) {
            $arcs[] = ['arc' => 'arc_'.$level, 'level' => $level, 'gaps' => $ids];
        }

        return $arcs;
    }

    /**
     * Flattens every direct depends_on edge in the gap index into a deterministic,
     * sorted list — the raw dependency graph a stop/go checkpoint can reason about.
     *
     * @param  list<array<string,mixed>>  $gapIndex
     * @return list<array{from:string,to:string}>
     */
    private function compileDependencyChains(array $gapIndex): array
    {
        $chains = [];
        foreach ($gapIndex as $gap) {
            $id = (string) ($gap['id'] ?? '');
            foreach (array_map('strval', (array) ($gap['depends_on'] ?? [])) as $dep) {
                $chains[] = ['from' => $dep, 'to' => $id];
            }
        }
        usort($chains, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        return $chains;
    }

    /**
     * A proof milestone is a taskable slice's required_evidence — the concrete artifact
     * that must exist before the gap can be counted as truly complete.
     *
     * @param  list<array<string,mixed>>  $taskableSlices
     * @return list<array<string,mixed>>
     */
    private function compileProofMilestones(array $taskableSlices): array
    {
        return array_values(array_map(
            static fn (array $slice): array => ['gap_id' => $slice['gap_id'], 'required_evidence' => $slice['required_evidence']],
            $taskableSlices,
        ));
    }

    /**
     * @param  array<string,array<string,mixed>>  $phases
     * @return list<array<string,mixed>>
     */
    private function compileSimplificationWaves(array $phases): array
    {
        $waves = [];
        foreach ($phases as $phaseName => $envelope) {
            $applied = (array) ($envelope['compression_applied'] ?? []);
            if ($applied !== []) {
                $waves[] = ['phase' => $phaseName, 'gaps' => array_values($applied)];
            }
        }

        return $waves;
    }

    /**
     * @param  list<string>  $unscheduledIds
     * @param  list<string>  $notQueueReadyIds
     * @return list<array<string,mixed>>
     */
    private function compileStopGoCheckpoints(array $unscheduledIds, bool $pressureCapped, array $notQueueReadyIds): array
    {
        return [
            ['checkpoint' => 'dependency_integrity', 'go' => $unscheduledIds === []],
            ['checkpoint' => 'queue_pressure', 'go' => ! $pressureCapped],
            ['checkpoint' => 'queue_readiness', 'go' => $notQueueReadyIds === []],
        ];
    }

    /**
     * @param  list<string>  $unscheduledIds
     * @param  list<string>  $notQueueReadyIds
     * @return list<string>
     */
    private function compileRiskNotes(array $unscheduledIds, bool $pressureCapped, array $notQueueReadyIds): array
    {
        $notes = [];
        if ($unscheduledIds !== []) {
            $notes[] = 'dependency_cycle_or_missing_dependency_detected';
        }
        if ($pressureCapped) {
            $notes[] = 'queue_pressure_above_cap_near_term_capacity_reduced';
        }
        if ($notQueueReadyIds !== []) {
            $notes[] = 'abstract_roadmap_items_not_queue_ready';
        }

        return $notes;
    }

    /**
     * Compiles each roadmap gap into a taskable slice — allowed_files, acceptance and
     * required_evidence — so a slice can be handed directly to the serving queue. A gap
     * that lacks any of these (an abstract roadmap item) is reported as not_queue_ready
     * instead of being silently scheduled with guessed/empty scope.
     *
     * @param  list<array<string,mixed>>  $gapIndex
     * @return array{0: list<array<string,mixed>>, 1: list<string>}
     */
    private function compileTaskableSlices(array $gapIndex): array
    {
        $slices = [];
        $notQueueReady = [];

        foreach ($gapIndex as $gap) {
            $id = (string) ($gap['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $allowedFiles = array_values(array_filter(array_map('strval', (array) ($gap['allowed_files'] ?? []))));
            $acceptance = array_values(array_filter(array_map('strval', (array) ($gap['acceptance_criteria'] ?? []))));
            $requiredEvidence = array_values(array_filter(array_map('strval', (array) ($gap['required_evidence'] ?? []))));

            if ($allowedFiles === [] || $acceptance === [] || $requiredEvidence === []) {
                $notQueueReady[] = $id;

                continue;
            }

            $slices[] = [
                'gap_id' => $id,
                'allowed_files' => $allowedFiles,
                'acceptance_criteria' => $acceptance,
                'required_evidence' => $requiredEvidence,
            ];
        }

        return [$slices, $notQueueReady];
    }

    /**
     * @param  list<string>         $ids
     * @param  list<array<string,mixed>>  $gapIndex
     * @param  list<string>         $compressionIds
     * @return array<string,mixed>
     */
    private function phaseEnvelope(array $ids, bool $pressureCapped, array $gapIndex, array $compressionIds): array
    {
        $gapMap = [];
        foreach ($gapIndex as $g) {
            $gapMap[(string) ($g['id'] ?? '')] = $g;
        }

        $chainCompletions  = array_values(array_filter($ids, static fn (string $id): bool => (array_values((array) ($gapMap[$id]['depends_on'] ?? [])) !== [])));
        $compressionApplied = array_values(array_intersect($ids, $compressionIds));

        return [
            'gaps'                  => $ids,
            'task_count'            => count($ids),
            'queue_pressure_capped' => $pressureCapped,
            'chain_completions'     => $chainCompletions,
            'compression_applied'   => $compressionApplied,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $ordered
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private function assignPhases(array $ordered, int $nearTermCap, int $midTermBase): array
    {
        $near = [];
        $mid  = [];
        $long = [];

        foreach (array_values($ordered) as $i => $gap) {
            $id = (string) ($gap['id'] ?? '');
            if ($i < $nearTermCap) {
                $near[] = $id;
            } elseif ($i < $nearTermCap + $midTermBase) {
                $mid[] = $id;
            } else {
                $long[] = $id;
            }
        }

        return [$near, $mid, $long];
    }

    /**
     * BFS level assignment, then sort by (level asc, impact desc) within level.
     *
     * Gaps that never become ready — because they sit in a dependency
     * cycle, or depend (directly or transitively) on a missing/cyclic id —
     * are NEVER silently scheduled with a guessed level. They are excluded
     * from the returned ordering and reported separately so callers can
     * never mistake "scheduled" for "claimed scheduled".
     *
     * @param  list<array<string,mixed>>  $gaps
     * @param  array<string,float>        $impactMap
     * @return array{0:list<array<string,mixed>>,1:list<string>,2:array<string,int>}
     */
    private function orderByDependencies(array $gaps, array $impactMap): array
    {
        $depsOf = [];
        foreach ($gaps as $gap) {
            $depsOf[(string) ($gap['id'] ?? '')] = array_values(array_map('strval', (array) ($gap['depends_on'] ?? [])));
        }

        $levels   = [];
        $assigned = [];
        $maxIter  = count($gaps) + 1;

        while (count($assigned) < count($gaps) && $maxIter-- > 0) {
            $progressed = false;
            foreach ($gaps as $gap) {
                $id = (string) ($gap['id'] ?? '');
                if (isset($assigned[$id])) {
                    continue;
                }
                $ready = true;
                foreach ($depsOf[$id] as $dep) {
                    if (! isset($assigned[$dep])) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $level = 0;
                    foreach ($depsOf[$id] as $dep) {
                        $level = max($level, ($levels[$dep] ?? 0) + 1);
                    }
                    $levels[$id]   = $level;
                    $assigned[$id] = true;
                    $progressed    = true;
                }
            }
            if (! $progressed) {
                break;
            }
        }

        $scheduled   = array_values(array_filter($gaps, static fn (array $g): bool => isset($assigned[(string) ($g['id'] ?? '')])));
        $unscheduled = array_values(array_filter(
            array_map(static fn (array $g): string => (string) ($g['id'] ?? ''), $gaps),
            static fn (string $id): bool => ! isset($assigned[$id]),
        ));

        usort($scheduled, static function (array $a, array $b) use ($levels, $impactMap): int {
            $la = $levels[(string) ($a['id'] ?? '')] ?? 0;
            $lb = $levels[(string) ($b['id'] ?? '')] ?? 0;
            if ($la !== $lb) {
                return $la - $lb;
            }

            return ((float) ($impactMap[(string) ($b['id'] ?? '')] ?? 0.0)) <=> ((float) ($impactMap[(string) ($a['id'] ?? '')] ?? 0.0));
        });

        return [$scheduled, $unscheduled, $levels];
    }
}
