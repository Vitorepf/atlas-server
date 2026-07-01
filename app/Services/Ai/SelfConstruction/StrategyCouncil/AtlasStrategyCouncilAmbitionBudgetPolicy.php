<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Pure Strategy Council policy. Chooses AMBITION LEVEL for the next Self-Construction slice while
 * respecting risk class, available budget, autonomy mode, and dependency-readiness facts.
 *
 * INPUT:
 *   { leverage_rank ∈ {high,medium,low},
 *     risk_class ∈ {low,medium,high,hardest},
 *     available_budget_units:int, required_budget_units:int,
 *     autonomy_mode ∈ {disabled,observe,propose,execute_guarded,execute_continuous},
 *     dependency_readiness:{verification:bool, rollback:bool, knowledge_sync:bool},
 *     evidence_present:bool }
 *
 * OUTPUT:
 *   { schema, ambition_level ∈ {narrow,standard,bold,hold}, reasons:list<string> }
 *
 * RULES (in evaluation order):
 *   hold       — !evidence_present, autonomy_mode=disabled, budget exceeded, OR risk exceeds mode
 *   bold       — leverage_rank='high' AND risk_class ∈ {low,medium} AND autonomy_mode=execute_continuous
 *                AND every dependency_readiness flag true
 *   standard   — autonomy_mode in {execute_guarded,execute_continuous} AND no hold trigger
 *   narrow     — otherwise (propose-only / no execution autonomy)
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (reasons sorted).
 *   - PURE.
 */
final class AtlasStrategyCouncilAmbitionBudgetPolicy
{
    public const SCHEMA = 'atlas.strategycouncil.ambition_budget_policy.v1';

    public const LEVEL_HOLD = 'hold';

    public const LEVEL_NARROW = 'narrow';

    public const LEVEL_STANDARD = 'standard';

    public const LEVEL_BOLD = 'bold';

    public const QUALITY_FLOOR_THRESHOLD = 7;

    /** Below this fraction, queue health / worker throughput / proof coverage / worker capacity are weak. */
    private const WEAK_FRACTION_THRESHOLD = 0.5;

    /** Structural leverage evidence, proof coverage, dedup state, and worker capacity floors for bold. */
    private const BOLD_PROOF_COVERAGE_FLOOR = 0.8;

    private const BOLD_WORKER_CAPACITY_FLOOR = 0.8;

    /**
     * @param  array{
     *     leverage_rank?:string,
     *     risk_class?:string,
     *     available_budget_units?:int,
     *     required_budget_units?:int,
     *     autonomy_mode?:string,
     *     dependency_readiness?:array{verification?:bool, rollback?:bool, knowledge_sync?:bool},
     *     evidence_present?:bool
     * }  $facts
     * @return array{schema:string, ambition_level:string, reasons:list<string>}
     */
    public function decide(array $facts): array
    {
        $leverage = (string) ($facts['leverage_rank'] ?? '');
        $risk = (string) ($facts['risk_class'] ?? '');
        $available = (int) ($facts['available_budget_units'] ?? 0);
        $required = (int) ($facts['required_budget_units'] ?? 0);
        $mode = (string) ($facts['autonomy_mode'] ?? '');
        $dep = is_array($facts['dependency_readiness'] ?? null) ? $facts['dependency_readiness'] : [];
        $evidence = (bool) ($facts['evidence_present'] ?? false);
        // Defaults preserve prior behavior when a fact is not supplied: absence never blocks by itself.
        $queueHealth = (float) ($facts['queue_health'] ?? 1.0);
        $workerThroughput = (float) ($facts['worker_throughput'] ?? 1.0);
        $contextFresh = (bool) ($facts['context_fresh'] ?? true);

        $reasons = [];

        // HOLD triggers
        if (! $evidence) {
            $reasons[] = 'hold:evidence_missing';
        }
        if ($queueHealth < self::WEAK_FRACTION_THRESHOLD) {
            $reasons[] = 'hold:queue_health_weak';
        }
        if ($workerThroughput < self::WEAK_FRACTION_THRESHOLD) {
            $reasons[] = 'hold:worker_throughput_weak';
        }
        if (! $contextFresh) {
            $reasons[] = 'hold:context_stale';
        }
        if ($mode === 'disabled') {
            $reasons[] = 'hold:autonomy_disabled';
        }
        if ($required > 0 && $available < $required) {
            $reasons[] = 'hold:budget_exceeded';
        }
        if ($risk === 'hardest' && ! in_array($mode, ['execute_continuous'], true)) {
            $reasons[] = 'hold:risk_exceeds_mode';
        }
        if ($risk === 'high' && ! in_array($mode, ['execute_guarded', 'execute_continuous'], true)) {
            $reasons[] = 'hold:risk_exceeds_mode';
        }

        if ($reasons !== []) {
            sort($reasons, SORT_STRING);

            return $this->envelope(self::LEVEL_HOLD, $reasons);
        }

        // BOLD test — structural leverage evidence, proof coverage, dedup state, and worker
        // capacity must ALL be strong; defaults preserve prior behavior when unsupplied.
        $allDeps = (bool) ($dep['verification'] ?? false)
            && (bool) ($dep['rollback'] ?? false)
            && (bool) ($dep['knowledge_sync'] ?? false);
        $proofCoverage = (float) ($facts['proof_coverage'] ?? 1.0);
        $dedupState = (string) ($facts['dedup_state'] ?? 'clean');
        $workerCapacity = (float) ($facts['worker_capacity'] ?? 1.0);
        if (
            $leverage === 'high'
            && in_array($risk, ['low', 'medium'], true)
            && $mode === 'execute_continuous'
            && $allDeps
            && $proofCoverage >= self::BOLD_PROOF_COVERAGE_FLOOR
            && $dedupState === 'clean'
            && $workerCapacity >= self::BOLD_WORKER_CAPACITY_FLOOR
        ) {
            return $this->envelope(self::LEVEL_BOLD, ['bold:high_leverage+ready_deps']);
        }

        // STANDARD test
        if (in_array($mode, ['execute_guarded', 'execute_continuous'], true)) {
            return $this->envelope(self::LEVEL_STANDARD, ['standard:execution_mode_ready']);
        }

        return $this->envelope(self::LEVEL_NARROW, ['narrow:propose_or_observe_mode']);
    }

    /**
     * Allocate total_budget_units across bugfix/capability/refactor/proof/expansion lanes.
     *
     * Rules (evaluated in order):
     *  1. bugfix_critical=true → all budget to bugfix, others 0 (emergency override)
     *  2. quality_score < QUALITY_FLOOR_THRESHOLD → expansion AND capability refused; remaining
     *     split across the repair/refactor/proof lanes only (bugfix, refactor, proof)
     *  3. otherwise → balanced even split across all 5 lanes
     *
     * @param  array{total_budget_units?:int, quality_score?:int, bugfix_critical?:bool}  $facts
     * @return array{lanes:array<string,int>, quality_floor_met:bool, reasons:list<string>}
     */
    public function allocate(array $facts): array
    {
        $total = (int) ($facts['total_budget_units'] ?? 0);
        $qualityScore = (int) ($facts['quality_score'] ?? 10);
        $bugfixCritical = (bool) ($facts['bugfix_critical'] ?? false);
        $qualityFloorMet = $qualityScore >= self::QUALITY_FLOOR_THRESHOLD;

        if ($bugfixCritical) {
            return [
                'lanes' => ['bugfix' => $total, 'capability' => 0, 'refactor' => 0, 'proof' => 0, 'expansion' => 0],
                'quality_floor_met' => $qualityFloorMet,
                'reasons' => ['bugfix_emergency:all_budget_to_bugfix'],
            ];
        }

        if (! $qualityFloorMet) {
            $perLane = (int) ($total / 3);

            return [
                'lanes' => ['bugfix' => $perLane, 'capability' => 0, 'refactor' => $perLane, 'proof' => $perLane, 'expansion' => 0],
                'quality_floor_met' => false,
                'reasons' => ['quality_floor:expansion_refused', 'quality_floor:capability_refused_repair_refactor_proof_reserved'],
            ];
        }

        $perLane = (int) ($total / 5);

        return [
            'lanes' => ['bugfix' => $perLane, 'capability' => $perLane, 'refactor' => $perLane, 'proof' => $perLane, 'expansion' => $perLane],
            'quality_floor_met' => true,
            'reasons' => ['balanced:even_distribution'],
        ];
    }

    /**
     * Allocates total_budget_units across research/refactor/task_fabric/proof/knowledge_sync
     * lanes. Anti-quota-farming: a lane whose recent_output_is_farmed flag is set gets its
     * share redirected to proof (forcing real verification work) instead of more of the same lane.
     *
     * @param  array{total_budget_units?:int, farmed_lanes?:list<string>}  $facts
     * @return array{lanes:array<string,int>, farmed_lanes_redirected:list<string>, reasons:list<string>}
     */
    public function allocateBudgetSlices(array $facts): array
    {
        $total = max(0, (int) ($facts['total_budget_units'] ?? 0));
        $farmedLanes = array_values(array_intersect(
            array_map('strval', (array) ($facts['farmed_lanes'] ?? [])),
            ['research', 'refactor', 'task_fabric', 'proof', 'knowledge_sync'],
        ));

        $lanes = ['research', 'refactor', 'task_fabric', 'proof', 'knowledge_sync'];
        $perLane = (int) ($total / count($lanes));
        $allocation = array_fill_keys($lanes, $perLane);

        foreach ($farmedLanes as $lane) {
            $redirected = $allocation[$lane];
            $allocation[$lane] = 0;
            $allocation['proof'] += $redirected;
        }

        $reasons = $farmedLanes === []
            ? ['balanced:even_distribution_across_five_lanes']
            : array_map(static fn (string $lane): string => "quota_farming_detected:{$lane}:redirected_to_proof", $farmedLanes);

        return [
            'lanes' => $allocation,
            'farmed_lanes_redirected' => $farmedLanes,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema:string, ambition_level:string, reasons:list<string>}
     */
    private function envelope(string $level, array $reasons): array
    {
        return ['schema' => self::SCHEMA, 'ambition_level' => $level, 'reasons' => $reasons];
    }
}
