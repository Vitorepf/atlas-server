<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure evaluator: compares a chosen task batch against rejected alternatives to
 * quantify what would have changed if the brain had chosen differently.
 *
 * Deltas are always "chosen minus best_alternative" (positive = chosen is better).
 * delta_risk: negative = chosen carries less risk (good).
 * delta_leverage: positive = chosen captures more leverage (good).
 * missed_unlocks = max(0, best_alternative.downstream_unlocks - chosen.downstream_unlocks).
 *
 * Regret levels:
 *   high   → any alternative beats chosen on BOTH downstream_unlocks AND risk_score
 *   low    → chosen has strong evidence (>=7) + implementability (>=7) and >=0 delta_leverage
 *   medium → everything else
 */
final class AtlasExternalBrainCounterfactualBatchEvaluator
{
    public const SCHEMA = 'atlas.external_brain.counterfactual_batch_evaluator.v1';

    public const REGRET_LOW = 'low';

    public const REGRET_MEDIUM = 'medium';

    public const REGRET_HIGH = 'high';

    private const QUALITY_FLOOR = 7.0;

    /**
     * @param  array<string,mixed>  $input  chosen_batch, alternatives
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $chosen = is_array($input['chosen_batch'] ?? null) ? $input['chosen_batch'] : [];
        $alternatives = is_array($input['alternatives'] ?? null)
            ? array_values(array_filter($input['alternatives'], 'is_array'))
            : [];

        if ($chosen === [] || $alternatives === []) {
            return [
                'schema_version' => self::SCHEMA,
                'delta_leverage' => 0.0,
                'delta_risk' => 0.0,
                'delta_backlog_cost' => 0.0,
                'missed_unlocks' => 0,
                'decision_regret_level' => self::REGRET_LOW,
                'comparison_count' => 0,
            ];
        }

        $chosenLeverage = (float) ($chosen['leverage_score'] ?? 0.0);
        $chosenRisk = (float) ($chosen['risk_score'] ?? 0.0);
        $chosenBacklog = (float) ($chosen['backlog_cost'] ?? 0.0);
        $chosenUnlocks = (int) ($chosen['downstream_unlocks'] ?? 0);
        $chosenEvidence = (float) ($chosen['evidence_strength'] ?? 0.0);
        $chosenImpl = (float) ($chosen['implementability'] ?? 0.0);

        $altLeverages = array_map(static fn (array $a): float => (float) ($a['leverage_score'] ?? 0.0), $alternatives);
        $altRisks = array_map(static fn (array $a): float => (float) ($a['risk_score'] ?? 0.0), $alternatives);
        $altBacklogs = array_map(static fn (array $a): float => (float) ($a['backlog_cost'] ?? 0.0), $alternatives);
        $altUnlocks = array_map(static fn (array $a): int => (int) ($a['downstream_unlocks'] ?? 0), $alternatives);

        $deltaLeverage = round($chosenLeverage - max($altLeverages), 2);
        $deltaRisk = round($chosenRisk - min($altRisks), 2);
        $deltaBacklog = round($chosenBacklog - min($altBacklogs), 2);
        $missedUnlocks = max(0, max($altUnlocks) - $chosenUnlocks);

        $regret = $this->classifyRegret($alternatives, $chosenRisk, $chosenUnlocks, $chosenEvidence, $chosenImpl, $deltaLeverage);

        return [
            'schema_version' => self::SCHEMA,
            'delta_leverage' => $deltaLeverage,
            'delta_risk' => $deltaRisk,
            'delta_backlog_cost' => $deltaBacklog,
            'missed_unlocks' => $missedUnlocks,
            'decision_regret_level' => $regret,
            'comparison_count' => count($alternatives),
        ];
    }

    private function classifyRegret(array $alternatives, float $chosenRisk, int $chosenUnlocks, float $chosenEvidence, float $chosenImpl, float $deltaLeverage): string
    {
        foreach ($alternatives as $alt) {
            $altUnlocks = (int) ($alt['downstream_unlocks'] ?? 0);
            $altRisk = (float) ($alt['risk_score'] ?? 0.0);
            if ($altUnlocks > $chosenUnlocks && $altRisk < $chosenRisk) {
                return self::REGRET_HIGH;
            }
        }

        if ($chosenEvidence >= self::QUALITY_FLOOR && $chosenImpl >= self::QUALITY_FLOOR && $deltaLeverage >= 0.0) {
            return self::REGRET_LOW;
        }

        return self::REGRET_MEDIUM;
    }
}
