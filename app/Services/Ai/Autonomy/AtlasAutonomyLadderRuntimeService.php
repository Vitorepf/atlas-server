<?php

namespace App\Services\Ai\Autonomy;

/**
 * Runtime for the Atlas Autonomy Ladder Promotion Runbook — the engine the
 * runbook says `AtlasAutonomyLadderRuntimeService` consults before approving a
 * promotion. Turns the 8 conceptual levels (L0 Assist .. L7 Self-Evolving) into
 * EXECUTABLE entry/exit criteria, signature requirements and automatic demote.
 *
 * Pure decision logic (no I/O): given a current level + measured metrics +
 * provided signatures it computes whether promotion is allowed, what is unmet,
 * and whether a demote is triggered. The measured metrics themselves come from
 * AtlasAutonomyMetricsAggregator; the auto-demote from AtlasAutonomyDemoteWatchdog.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
 */
class AtlasAutonomyLadderRuntimeService
{
    public const SCHEMA = 'atlas.autonomy.promotion_request.v1';

    /**
     * Canonical 8-level ladder with measurable exit criteria and the signature
     * tier required to promote INTO each level. Mirrors the runbook table.
     *
     * @var array<int,array{level:string,name:string,signature:string,exit:array<int,array{metric:string,comparator:string,value:float}>}>
     */
    private const LADDER = [
        ['level' => 'L0', 'name' => 'Assist', 'signature' => 'single', 'exit' => [
            ['metric' => 'assist_sessions', 'comparator' => '>=', 'value' => 50],
            ['metric' => 'acceptance_rate', 'comparator' => '>=', 'value' => 0.80],
            ['metric' => 'severe_hallucination_count', 'comparator' => '<=', 'value' => 0],
        ]],
        ['level' => 'L1', 'name' => 'Slice Co-Pilot', 'signature' => 'single', 'exit' => [
            ['metric' => 'consecutive_green_slices', 'comparator' => '>=', 'value' => 20],
            ['metric' => 'scope_violation_count', 'comparator' => '<=', 'value' => 0],
            ['metric' => 'repair_loop_count', 'comparator' => '<=', 'value' => 1],
        ]],
        ['level' => 'L2', 'name' => 'Multi-Slice Pair', 'signature' => 'single', 'exit' => [
            ['metric' => 'green_pair_obras', 'comparator' => '>=', 'value' => 30],
            ['metric' => 'regression_catch_rate', 'comparator' => '>=', 'value' => 0.90],
        ]],
        ['level' => 'L3', 'name' => 'Feature Owner', 'signature' => 'single', 'exit' => [
            ['metric' => 'cert_green_features', 'comparator' => '>=', 'value' => 15],
            ['metric' => 'blocker_in_review_per_feature', 'comparator' => '<=', 'value' => 1],
        ]],
        ['level' => 'L4', 'name' => 'Obra Owner', 'signature' => 'dual', 'exit' => [
            ['metric' => 'consecutive_cert_green_obras', 'comparator' => '>=', 'value' => 5],
            ['metric' => 'cert_phase_rollback_count', 'comparator' => '<=', 'value' => 0],
            ['metric' => 'dual_signature_count', 'comparator' => '>=', 'value' => 5],
        ]],
        ['level' => 'L5', 'name' => 'Department Owner', 'signature' => 'dual', 'exit' => [
            ['metric' => 'days_without_intervention', 'comparator' => '>=', 'value' => 90],
            ['metric' => 'department_maturity_level', 'comparator' => '>=', 'value' => 4],
        ]],
        ['level' => 'L6', 'name' => 'Multi-Department Conductor', 'signature' => 'dual_plus_architect', 'exit' => [
            ['metric' => 'days_with_3plus_departments', 'comparator' => '>=', 'value' => 30],
            ['metric' => 'cross_dept_blocker_resolution_p95_hours', 'comparator' => '<=', 'value' => 2],
        ]],
        ['level' => 'L7', 'name' => 'Self-Evolving', 'signature' => 'dual_plus_architect', 'exit' => [
            ['metric' => 'approved_self_construction_proposals', 'comparator' => '>=', 'value' => 10],
            ['metric' => 'broken_invariant_count', 'comparator' => '<=', 'value' => 0],
            ['metric' => 'trust_ledger_score', 'comparator' => '>=', 'value' => 0.95],
        ]],
    ];

    /**
     * The canonical ladder as a read-model (for atlas:autonomy:ladder and surfaces).
     *
     * @return array<int,array<string,mixed>>
     */
    public function ladder(): array
    {
        $rows = [];
        foreach (self::LADDER as $i => $rung) {
            $rows[] = [
                'rank' => $i,
                'level' => $rung['level'],
                'name' => $rung['name'],
                'promotion_signature' => self::LADDER[$i + 1]['signature'] ?? null,
                'exit_criteria' => $rung['exit'],
                'next_level' => self::LADDER[$i + 1]['level'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Evaluate a promotion request from $currentLevel given measured $metrics and
     * provided $signatures. The exit criteria of the CURRENT level must all be
     * satisfied AND the signature tier required to enter the NEXT level must be
     * present.
     *
     * @param  array<string,float|int>  $metrics
     * @param  array{operator?:bool,architect?:bool,architect_human_review?:bool,trust_ledger_score?:float}  $signatures
     * @return array<string,mixed>
     */
    public function evaluatePromotion(string $currentLevel, array $metrics, array $signatures = []): array
    {
        $index = $this->levelIndex($currentLevel);
        if ($index === null) {
            return $this->result($currentLevel, null, false, [], [], 'unknown_level', 'unknown current level', []);
        }
        if ($index >= count(self::LADDER) - 1) {
            return $this->result($currentLevel, null, false, [], [], 'at_ceiling', 'already at L7 (top of ladder)', []);
        }

        $rung = self::LADDER[$index];
        $next = self::LADDER[$index + 1];

        $met = [];
        $unmet = [];
        foreach ($rung['exit'] as $criterion) {
            $observed = $metrics[$criterion['metric']] ?? null;
            $ok = $observed !== null
                && $this->comparatorSatisfied($criterion['comparator'], (float) $observed, (float) $criterion['value']);
            $row = [
                'metric' => $criterion['metric'],
                'comparator' => $criterion['comparator'],
                'threshold' => $criterion['value'],
                'observed' => $observed,
                'missing' => $observed === null,
            ];
            if ($ok) {
                $met[] = $row;
            } else {
                $unmet[] = $row;
            }
        }

        $requiredSignature = $next['signature'];
        $signatureCheck = $this->signaturesSatisfied($requiredSignature, $signatures);
        $eligible = $unmet === [] && $signatureCheck['satisfied'];

        return $this->result(
            $currentLevel,
            $next['level'],
            $eligible,
            $met,
            $unmet,
            $eligible ? 'promote' : 'blocked',
            $eligible
                ? "eligible to promote {$currentLevel} -> {$next['level']}"
                : 'promotion blocked: '.($unmet !== [] ? 'unmet exit criteria' : 'missing required signatures'),
            $signatureCheck,
        );
    }

    /**
     * Automatic demote rule: a level whose exit criteria are breached for the
     * required number of consecutive most-recent cycles drops one level. The
     * runbook sets the trigger at 2 consecutive breaching cycles.
     *
     * @param  array<int,array<string,float|int>>  $recentCycles  oldest..newest metric maps
     * @return array<string,mixed>
     */
    public function evaluateDemote(string $currentLevel, array $recentCycles, int $consecutiveBreachTrigger = 2): array
    {
        $index = $this->levelIndex($currentLevel);
        if ($index === null || $index === 0) {
            return [
                'schema_version' => self::SCHEMA,
                'demote' => false,
                'from_level' => $currentLevel,
                'to_level' => null,
                'reason' => $index === 0 ? 'at_floor' : 'unknown_level',
            ];
        }

        $rung = self::LADDER[$index];
        $tail = array_slice($recentCycles, -$consecutiveBreachTrigger);
        $allBreach = count($tail) >= $consecutiveBreachTrigger;
        $breaching = [];
        foreach ($tail as $cycleMetrics) {
            $cycleBreaches = $this->cycleBreaches($rung['exit'], $cycleMetrics);
            if ($cycleBreaches === []) {
                $allBreach = false;
            } else {
                $breaching[] = $cycleBreaches;
            }
        }

        $demote = $allBreach;

        return [
            'schema_version' => self::SCHEMA,
            'demote' => $demote,
            'from_level' => $currentLevel,
            'to_level' => $demote ? self::LADDER[$index - 1]['level'] : $currentLevel,
            'consecutive_breach_trigger' => $consecutiveBreachTrigger,
            'breaching_cycles' => $breaching,
            'reason' => $demote
                ? "exit criteria breached for {$consecutiveBreachTrigger} consecutive cycles"
                : 'criteria held within tolerance',
        ];
    }

    public function comparatorSatisfied(string $comparator, float $observed, float $threshold): bool
    {
        $epsilon = 1e-9;

        return match ($comparator) {
            '>=' => $observed >= $threshold - $epsilon,
            '<=' => $observed <= $threshold + $epsilon,
            '>' => $observed > $threshold,
            '<' => $observed < $threshold,
            '==' => abs($observed - $threshold) <= $epsilon,
            default => false,
        };
    }

    private function levelIndex(string $level): ?int
    {
        foreach (self::LADDER as $i => $rung) {
            if (strcasecmp($rung['level'], trim($level)) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array{metric:string,comparator:string,value:float}>  $exit
     * @param  array<string,float|int>  $metrics
     * @return array<int,string>
     */
    private function cycleBreaches(array $exit, array $metrics): array
    {
        $breaches = [];
        foreach ($exit as $criterion) {
            $observed = $metrics[$criterion['metric']] ?? null;
            if ($observed === null
                || ! $this->comparatorSatisfied($criterion['comparator'], (float) $observed, (float) $criterion['value'])) {
                $breaches[] = $criterion['metric'];
            }
        }

        return $breaches;
    }

    /**
     * @param  array<string,mixed>  $signatures
     * @return array{satisfied:bool,required:string,have:array<int,string>,missing:array<int,string>}
     */
    private function signaturesSatisfied(string $required, array $signatures): array
    {
        $have = [];
        if (($signatures['operator'] ?? false) === true) {
            $have[] = 'operator';
        }
        if (($signatures['architect'] ?? false) === true) {
            $have[] = 'architect';
        }
        if (($signatures['architect_human_review'] ?? false) === true) {
            $have[] = 'architect_human_review';
        }

        $need = match ($required) {
            'single' => ['operator'],
            'dual' => ['operator', 'architect'],
            'dual_plus_architect' => ['operator', 'architect', 'architect_human_review'],
            default => ['operator'],
        };
        $missing = array_values(array_diff($need, $have));

        return [
            'satisfied' => $missing === [],
            'required' => $required,
            'have' => $have,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $met
     * @param  array<int,array<string,mixed>>  $unmet
     * @param  array<string,mixed>  $signatureCheck
     * @return array<string,mixed>
     */
    private function result(
        string $current,
        ?string $next,
        bool $eligible,
        array $met,
        array $unmet,
        string $decision,
        string $message,
        array $signatureCheck,
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'current_level' => $current,
            'next_level' => $next,
            'eligible' => $eligible,
            'decision' => $decision,
            'message' => $message,
            'met_criteria' => $met,
            'unmet_criteria' => $unmet,
            'signatures' => $signatureCheck,
        ];
    }
}
