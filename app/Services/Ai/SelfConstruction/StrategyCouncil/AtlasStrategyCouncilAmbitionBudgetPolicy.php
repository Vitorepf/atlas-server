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

        $reasons = [];

        // HOLD triggers
        if (! $evidence) {
            $reasons[] = 'hold:evidence_missing';
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

        // BOLD test
        $allDeps = (bool) ($dep['verification'] ?? false)
            && (bool) ($dep['rollback'] ?? false)
            && (bool) ($dep['knowledge_sync'] ?? false);
        if (
            $leverage === 'high'
            && in_array($risk, ['low', 'medium'], true)
            && $mode === 'execute_continuous'
            && $allDeps
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
     * Allocate total_budget_units across bugfix/capability/refactor/expansion lanes.
     *
     * Rules (evaluated in order):
     *  1. bugfix_critical=true → all budget to bugfix, others 0 (emergency override)
     *  2. quality_score < QUALITY_FLOOR_THRESHOLD → expansion refused; remaining split evenly
     *  3. otherwise → balanced even split across all 4 lanes
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
                'lanes' => ['bugfix' => $total, 'capability' => 0, 'refactor' => 0, 'expansion' => 0],
                'quality_floor_met' => $qualityFloorMet,
                'reasons' => ['bugfix_emergency:all_budget_to_bugfix'],
            ];
        }

        if (! $qualityFloorMet) {
            $perLane = (int) ($total / 3);

            return [
                'lanes' => ['bugfix' => $perLane, 'capability' => $perLane, 'refactor' => $perLane, 'expansion' => 0],
                'quality_floor_met' => false,
                'reasons' => ['quality_floor:expansion_refused'],
            ];
        }

        $perLane = (int) ($total / 4);

        return [
            'lanes' => ['bugfix' => $perLane, 'capability' => $perLane, 'refactor' => $perLane, 'expansion' => $perLane],
            'quality_floor_met' => true,
            'reasons' => ['balanced:even_distribution'],
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
