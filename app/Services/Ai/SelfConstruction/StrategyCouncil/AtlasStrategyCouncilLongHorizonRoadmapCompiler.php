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

        $ordered = $this->orderByDependencies($gapIndex, $impactMap);

        [$nearIds, $midIds, $longIds] = $this->assignPhases($ordered, $nearTermCap, $midTermBase);

        return [
            'schema_version' => self::SCHEMA,
            'phases' => [
                'near_term' => $this->phaseEnvelope($nearIds, $pressureCapped, $gapIndex, $compressionIds),
                'mid_term'  => $this->phaseEnvelope($midIds, false, $gapIndex, $compressionIds),
                'long_term' => $this->phaseEnvelope($longIds, false, $gapIndex, $compressionIds),
            ],
            'prerequisite_chains_respected' => true,
            'total_gaps_scheduled'          => count($ordered),
            'near_term_capacity_used'       => $nearTermCap,
            'queue_pressure_capped'         => $pressureCapped,
        ];
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
     * @param  list<array<string,mixed>>  $gaps
     * @param  array<string,float>        $impactMap
     * @return list<array<string,mixed>>
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
                }
            }
        }

        $sorted = $gaps;
        usort($sorted, static function (array $a, array $b) use ($levels, $impactMap): int {
            $la = $levels[(string) ($a['id'] ?? '')] ?? 0;
            $lb = $levels[(string) ($b['id'] ?? '')] ?? 0;
            if ($la !== $lb) {
                return $la - $lb;
            }

            return ((float) ($impactMap[(string) ($b['id'] ?? '')] ?? 0.0)) <=> ((float) ($impactMap[(string) ($a['id'] ?? '')] ?? 0.0));
        });

        return $sorted;
    }
}
