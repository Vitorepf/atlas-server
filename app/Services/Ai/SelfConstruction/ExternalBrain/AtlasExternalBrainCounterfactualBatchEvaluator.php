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
 *            OR any alternative has similar leverage (≥90%) + lower template_farm_risk
 *               + higher outcome_learning_gain or consolidation_debt burn-down
 *   low    → chosen has strong evidence (>=7) + implementability (>=7) and >=0 delta_leverage
 *   medium → everything else
 *
 * When regret is not low, output includes:
 *   learned_from_alternative_id — id of the alternative to learn from
 *   missed_learning_gain        — outcome_learning_gain gap vs best alternative
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainCounterfactualBatchEvaluator
{
    public const SCHEMA = 'atlas.external_brain.counterfactual_batch_evaluator.v1';

    public const REGRET_LOW = 'low';

    public const REGRET_MEDIUM = 'medium';

    public const REGRET_HIGH = 'high';

    private const QUALITY_FLOOR = 7.0;

    private const SIMILAR_LEVERAGE_RATIO = 0.9;

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
                'schema_version'              => self::SCHEMA,
                'delta_leverage'              => 0.0,
                'delta_risk'                  => 0.0,
                'delta_backlog_cost'          => 0.0,
                'missed_unlocks'              => 0,
                'decision_regret_level'       => self::REGRET_LOW,
                'comparison_count'            => 0,
                'missed_learning_gain'        => 0.0,
                'learned_from_alternative_id' => null,
            ];
        }

        $chosenLeverage     = (float) ($chosen['leverage_score']       ?? 0.0);
        $chosenRisk         = (float) ($chosen['risk_score']           ?? 0.0);
        $chosenBacklog      = (float) ($chosen['backlog_cost']         ?? 0.0);
        $chosenUnlocks      = (int)   ($chosen['downstream_unlocks']   ?? 0);
        $chosenEvidence     = (float) ($chosen['evidence_strength']    ?? 0.0);
        $chosenImpl         = (float) ($chosen['implementability']     ?? 0.0);
        $chosenLearning     = (float) ($chosen['outcome_learning_gain'] ?? 0.0);
        $chosenTemplateFarm = (float) ($chosen['template_farm_risk']   ?? 0.0);
        $chosenConsolidation = (float) ($chosen['consolidation_debt']  ?? 0.0);

        $altLeverages = array_map(static fn (array $a): float => (float) ($a['leverage_score']        ?? 0.0), $alternatives);
        $altRisks     = array_map(static fn (array $a): float => (float) ($a['risk_score']            ?? 0.0), $alternatives);
        $altBacklogs  = array_map(static fn (array $a): float => (float) ($a['backlog_cost']          ?? 0.0), $alternatives);
        $altUnlocks   = array_map(static fn (array $a): int   => (int)   ($a['downstream_unlocks']   ?? 0),   $alternatives);
        $altLearnings = array_map(static fn (array $a): float => (float) ($a['outcome_learning_gain'] ?? 0.0), $alternatives);

        $deltaLeverage  = round($chosenLeverage - max($altLeverages), 2);
        $deltaRisk      = round($chosenRisk     - min($altRisks),     2);
        $deltaBacklog   = round($chosenBacklog  - min($altBacklogs),  2);
        $missedUnlocks  = max(0, max($altUnlocks) - $chosenUnlocks);
        $missedLearning = round(max(0.0, max($altLearnings) - $chosenLearning), 2);

        [$regret, $learnedFromId] = $this->classifyRegret(
            $alternatives,
            $chosenRisk,
            $chosenUnlocks,
            $chosenEvidence,
            $chosenImpl,
            $deltaLeverage,
            $chosenLeverage,
            $chosenLearning,
            $chosenTemplateFarm,
            $chosenConsolidation,
        );

        return [
            'schema_version'              => self::SCHEMA,
            'delta_leverage'              => $deltaLeverage,
            'delta_risk'                  => $deltaRisk,
            'delta_backlog_cost'          => $deltaBacklog,
            'missed_unlocks'              => $missedUnlocks,
            'decision_regret_level'       => $regret,
            'comparison_count'            => count($alternatives),
            'missed_learning_gain'        => $missedLearning,
            'learned_from_alternative_id' => $regret !== self::REGRET_LOW ? $learnedFromId : null,
        ];
    }

    /** @return array{string, string|null} [regret_level, learned_from_alternative_id] */
    private function classifyRegret(
        array $alternatives,
        float $chosenRisk,
        int $chosenUnlocks,
        float $chosenEvidence,
        float $chosenImpl,
        float $deltaLeverage,
        float $chosenLeverage,
        float $chosenLearning,
        float $chosenTemplateFarm,
        float $chosenConsolidation,
    ): array {
        $similarLeverageFloor = $chosenLeverage * self::SIMILAR_LEVERAGE_RATIO;

        foreach ($alternatives as $alt) {
            $altUnlocks = (int)   ($alt['downstream_unlocks'] ?? 0);
            $altRisk    = (float) ($alt['risk_score']         ?? 0.0);

            // Condition 1: alternative beats on both unlocks and risk
            if ($altUnlocks > $chosenUnlocks && $altRisk < $chosenRisk) {
                return [self::REGRET_HIGH, (string) ($alt['id'] ?? '')];
            }
        }

        foreach ($alternatives as $alt) {
            $altLeverage      = (float) ($alt['leverage_score']        ?? 0.0);
            $altTemplateFarm  = (float) ($alt['template_farm_risk']    ?? 0.0);
            $altLearning      = (float) ($alt['outcome_learning_gain'] ?? 0.0);
            $altConsolidation = (float) ($alt['consolidation_debt']    ?? 0.0);

            // Condition 2: waste-reduction with preserved leverage
            if (
                $altLeverage >= $similarLeverageFloor
                && $altTemplateFarm < $chosenTemplateFarm
                && ($altLearning > $chosenLearning || $altConsolidation > $chosenConsolidation)
            ) {
                return [self::REGRET_HIGH, (string) ($alt['id'] ?? '')];
            }
        }

        if ($chosenEvidence >= self::QUALITY_FLOOR && $chosenImpl >= self::QUALITY_FLOOR && $deltaLeverage >= 0.0) {
            return [self::REGRET_LOW, null];
        }

        // Medium regret: return id of alternative with highest learning gain
        $bestId      = null;
        $bestLearning = -1.0;
        foreach ($alternatives as $alt) {
            $altLearning = (float) ($alt['outcome_learning_gain'] ?? 0.0);
            if ($altLearning > $bestLearning) {
                $bestLearning = $altLearning;
                $bestId       = (string) ($alt['id'] ?? '');
            }
        }

        return [self::REGRET_MEDIUM, $bestId];
    }
}
