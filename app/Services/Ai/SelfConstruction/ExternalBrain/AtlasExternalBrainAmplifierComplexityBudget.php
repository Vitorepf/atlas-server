<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure budget manager. Evaluates whether the model-amplifier subsystem is within
 * its complexity budget and recommends simplifications.
 *
 * Input facts:
 *   components — list of {id, type, usage_score, slo_met, has_value_proof}.
 *     type          — 'scaffold'|'gate'|'judge'|'telemetry'.
 *     usage_score   — float 0–1 (how actively used).
 *     slo_met       — bool (component meets its SLO).
 *     has_value_proof — bool (component has proven downstream value).
 *   budgets    — optional {scaffold, gate, judge, telemetry} with per-type limits.
 *
 * AC2 — Over-complexity flagged when count of active components of a type exceeds its limit:
 *   defaults: scaffold=10, gate=8, judge=5, telemetry=20.
 *
 * AC3 — Per-component recommendation:
 *   'retire'      — usage_score < 0.20 OR (slo_met=false AND has_value_proof=false).
 *   'consolidate' — (usage_score >= 0.20 AND slo_met=false) OR usage_score in [0.20, 0.70).
 *   'keep'        — usage_score >= 0.70 AND slo_met=true.
 *   Priority: retire > keep > consolidate.
 *
 * AC4 outputs: within_budget, over_budget_dimensions, recommended_simplifications,
 *   preserved_items.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainAmplifierComplexityBudget
{
    public const SCHEMA = 'atlas.external_brain.amplifier_complexity_budget.v1';

    private const DEFAULTS = ['scaffold' => 10, 'gate' => 8, 'judge' => 5, 'telemetry' => 20];

    private const RETIRE_USAGE_THRESHOLD = 0.20;
    private const KEEP_USAGE_THRESHOLD   = 0.70;

    private const VALID_TYPES = ['scaffold', 'gate', 'judge', 'telemetry'];

    /** Base per-component complexity budget before lift adjustment (arbitrary units). */
    private const BASE_COMPLEXITY_LIMIT = 10.0;

    private const WEIGHT_PROMPT_LENGTH   = 1 / 200.0;
    private const WEIGHT_DEPENDENCY      = 1.0;
    private const WEIGHT_MAINTENANCE     = 2.0;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $rawComponents = is_array($facts['components'] ?? null) ? $facts['components'] : [];
        $rawBudgets    = is_array($facts['budgets']    ?? null) ? $facts['budgets']    : [];

        $limits = $this->resolveLimits($rawBudgets);

        // Count active components per type and collect metadata.
        $countByType    = array_fill_keys(self::VALID_TYPES, 0);
        $components     = [];

        foreach ($rawComponents as $raw) {
            $id    = (string) ($raw['id']   ?? '');
            $type  = strtolower(trim((string) ($raw['type'] ?? '')));
            if (! in_array($type, self::VALID_TYPES, true)) {
                continue;
            }
            $usage      = max(0.0, min(1.0, (float) ($raw['usage_score']    ?? 0.0)));
            $sloMet     = (bool) ($raw['slo_met']         ?? true);
            $valueProof = (bool) ($raw['has_value_proof'] ?? false);
            $promptLength    = max(0, (int) ($raw['prompt_length']    ?? 0));
            $dependencyCount = max(0, (int) ($raw['dependency_count'] ?? 0));
            $maintenanceCost = max(0.0, (float) ($raw['maintenance_cost'] ?? 0.0));
            $measuredLift    = max(0.0, (float) ($raw['measured_lift']    ?? 0.0));

            $countByType[$type]++;
            $components[] = compact('id', 'type', 'usage', 'sloMet', 'valueProof', 'promptLength', 'dependencyCount', 'maintenanceCost', 'measuredLift');
        }

        // AC2: detect over-budget dimensions.
        $overBudget = [];
        foreach (self::VALID_TYPES as $type) {
            if ($countByType[$type] > $limits[$type]) {
                $overBudget[] = [
                    'type'    => $type,
                    'current' => $countByType[$type],
                    'limit'   => $limits[$type],
                ];
            }
        }

        // AC3: per-component recommendations.
        $simplifications = [];
        $preserved       = [];
        $componentEvaluations = [];
        $anyOverComplexityBudget = false;

        foreach ($components as $c) {
            [$rec, $reason] = $this->recommend($c['usage'], $c['sloMet'], $c['valueProof']);

            $complexityScore  = $this->complexityScore($c['promptLength'], $c['dependencyCount'], $c['maintenanceCost']);
            $liftAdjustedLimit = $this->liftAdjustedLimit($c['measuredLift']);
            $overComplexityBudget = $complexityScore > $liftAdjustedLimit;

            if ($overComplexityBudget) {
                $anyOverComplexityBudget = true;
                // AC2/AC3: complexity exceeding the lift-adjusted limit always blocks/retires,
                // regardless of how good usage_score/slo_met otherwise look.
                $rec    = 'retire';
                $reason = 'complexity_exceeds_lift_adjusted_limit';
            }

            $overBudgetReason = $overComplexityBudget
                ? $this->overBudgetReasons($c['promptLength'], $c['dependencyCount'], $c['maintenanceCost'], $c['measuredLift'])
                : [];

            $componentEvaluations[] = [
                'id'                    => $c['id'],
                'type'                  => $c['type'],
                'complexity_score'      => round($complexityScore, 4),
                'lift_adjusted_limit'   => round($liftAdjustedLimit, 4),
                'budget_status'         => $overComplexityBudget ? 'over_budget' : 'within_budget',
                'over_budget_reason'    => $overBudgetReason,
                'simplification_hint'   => $overComplexityBudget
                    ? $this->simplificationHint($c['promptLength'], $c['dependencyCount'], $c['maintenanceCost'])
                    : null,
            ];

            if ($rec === 'keep') {
                $preserved[] = $c['id'];
            } else {
                $simplifications[] = [
                    'id'             => $c['id'],
                    'type'           => $c['type'],
                    'recommendation' => $rec,
                    'reason'         => $reason,
                ];
            }
        }

        return [
            'schema_version'             => self::SCHEMA,
            'within_budget'              => empty($overBudget),
            'over_budget_dimensions'     => $overBudget,
            'recommended_simplifications' => $simplifications,
            'preserved_items'            => $preserved,
            'budget_status'              => (empty($overBudget) && ! $anyOverComplexityBudget) ? 'within_budget' : 'over_budget',
            'component_budget_evaluations' => $componentEvaluations,
        ];
    }

    private function complexityScore(int $promptLength, int $dependencyCount, float $maintenanceCost): float
    {
        return ($promptLength * self::WEIGHT_PROMPT_LENGTH)
            + ($dependencyCount * self::WEIGHT_DEPENDENCY)
            + ($maintenanceCost * self::WEIGHT_MAINTENANCE);
    }

    private function liftAdjustedLimit(float $measuredLift): float
    {
        return self::BASE_COMPLEXITY_LIMIT * (1.0 + $measuredLift);
    }

    /** @return list<string> */
    private function overBudgetReasons(int $promptLength, int $dependencyCount, float $maintenanceCost, float $measuredLift): array
    {
        $reasons = [];
        if ($promptLength > 1000) {
            $reasons[] = 'high_prompt_length';
        }
        if ($dependencyCount > 3) {
            $reasons[] = 'high_dependency_count';
        }
        if ($maintenanceCost > 2.0) {
            $reasons[] = 'high_maintenance_cost';
        }
        if ($measuredLift < 0.2) {
            $reasons[] = 'low_measured_lift';
        }

        return $reasons !== [] ? $reasons : ['complexity_exceeds_lift_adjusted_limit'];
    }

    private function simplificationHint(int $promptLength, int $dependencyCount, float $maintenanceCost): string
    {
        if ($promptLength > 1000) {
            return 'shorten the scaffold prompt before its next revision';
        }
        if ($dependencyCount > 3) {
            return 'reduce the number of dependencies this scaffold pulls in';
        }
        if ($maintenanceCost > 2.0) {
            return 'simplify or automate the maintenance burden of this scaffold';
        }

        return 'reduce overall complexity until it fits within the lift-adjusted budget';
    }

    private function resolveLimits(array $raw): array
    {
        $limits = self::DEFAULTS;
        foreach (self::VALID_TYPES as $type) {
            if (array_key_exists($type, $raw) && is_int($raw[$type])) {
                $limits[$type] = max(0, $raw[$type]);
            }
        }

        return $limits;
    }

    private function recommend(float $usage, bool $sloMet, bool $valueProof): array
    {
        // Retire: low usage OR no SLO + no proof.
        if ($usage < self::RETIRE_USAGE_THRESHOLD || (! $sloMet && ! $valueProof)) {
            return ['retire', 'low_usage_or_no_value_proof'];
        }

        // Keep: high usage AND meets SLO.
        if ($usage >= self::KEEP_USAGE_THRESHOLD && $sloMet) {
            return ['keep', 'high_usage_and_slo_met'];
        }

        // Consolidate: everything else (mid-usage or SLO miss with some value).
        return ['consolidate', 'moderate_usage_or_slo_miss'];
    }
}
