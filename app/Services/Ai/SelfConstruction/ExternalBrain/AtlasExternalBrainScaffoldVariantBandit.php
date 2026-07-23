<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure bandit: ranks scaffold variants by weighted historical outcomes and
 * adds an exploration bonus for under-sampled variants, scoped per model_tier
 * and task_class. Operates on pre-filtered variant_outcomes — no live I/O.
 *
 * UCB score = weighted_outcome + exploration_bonus
 *   weighted_outcome = success_rate*0.40
 *                    + heldout_pass_rate*0.20
 *                    + value_norm*0.15
 *                    + green_commit_rate*0.10
 *                    + (1−give_back_rate)*0.10
 *                    + cost_score*0.05
 *
 *   cost_score = 1 − min(1, avg_cost / MAX_AVG_COST)
 *
 *   exploration_bonus = EXPLORATION_FACTOR*(1−runs/MIN_EVIDENCE) when runs < MIN_EVIDENCE
 *   Unsampled variants receive bonus = EXPLORATION_FACTOR*2 so they are explored first.
 *
 * Quarantine: variants with proxy_leak_rate > PROXY_LEAK_CEILING AND runs ≥ MIN_EVIDENCE
 *   are quarantined and excluded from selection entirely (AC2).
 *
 * overfit_penalty (AC3 new) = OVERFIT_PENALTY_WEIGHT × max(0, success_rate − heldout_pass_rate),
 *   subtracted from weighted_outcome. A variant that looks good live (success_rate) but fails to
 *   generalize (low heldout_pass_rate) is penalized on the generalization GAP itself, not merely
 *   via heldout's existing weight — so raw throughput never masks overfit risk.
 *
 * exploration_reason / expected_quality_lift (AC4 new, top-level output fields, present whenever
 * a variant is selected): exploration_reason explains WHY the selected variant won
 * (unsampled_exploration_priority | low_evidence_best_available | proven_quality_leader);
 * expected_quality_lift = selected.weighted − average(weighted of every other scored variant),
 * or selected.weighted itself when it is the only scored variant (lift over doing nothing).
 *
 * selected_variant: highest UCB score among non-quarantined variants (variant_id tie-breaker).
 * exploration_variants: all non-quarantined variants with total_runs < MIN_EVIDENCE (except selected).
 * confidence: high≥MIN_EVIDENCE*2 runs, medium≥MIN_EVIDENCE, low otherwise.
 * evidence_counts: {variant_id → total_runs} for all variants.
 * rejected_variants: non-quarantined variants where weighted_outcome < REJECTION_THRESHOLD AND
 *   runs ≥ MIN_EVIDENCE.
 * quarantined_variants: variants removed from selection due to proxy_leak_rate excess.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainScaffoldVariantBandit
{
    public const SCHEMA = 'atlas.external_brain.scaffold_variant_bandit.v1';

    public const MIN_EVIDENCE = 5;

    public const EXPLORATION_FACTOR = 0.3;

    public const REJECTION_THRESHOLD = 0.30;

    public const PROXY_LEAK_CEILING = 0.25;

    public const OVERFIT_PENALTY_WEIGHT = 0.15;

    private const MAX_AVG_COST = 10.0;

    /** Context-dependent weight profiles: high_risk favors proven safety, low_risk favors cost/value. */
    private const WEIGHT_PROFILES = [
        'high_risk' => ['success' => 0.45, 'heldout' => 0.30, 'value' => 0.05, 'green' => 0.10, 'give_back' => 0.08, 'cost' => 0.02],
        'low_risk'  => ['success' => 0.25, 'heldout' => 0.10, 'value' => 0.25, 'green' => 0.10, 'give_back' => 0.10, 'cost' => 0.20],
        'default'   => ['success' => 0.40, 'heldout' => 0.20, 'value' => 0.15, 'green' => 0.10, 'give_back' => 0.10, 'cost' => 0.05],
    ];

    /**
     * @param  array<string,mixed>  $input  model_tier, task_class, task_family, risk_level, variant_outcomes
     * @return array<string,mixed>
     */
    public function select(array $input): array
    {
        $modelTier  = (string) ($input['model_tier'] ?? '');
        $taskClass  = (string) ($input['task_class'] ?? '');
        $taskFamily = (string) ($input['task_family'] ?? '');
        $riskLevel  = strtolower((string) ($input['risk_level'] ?? 'medium'));
        $variants   = is_array($input['variant_outcomes'] ?? null) ? $input['variant_outcomes'] : [];

        $profileName = match ($riskLevel) {
            'high' => 'high_risk',
            'low'  => 'low_risk',
            default => 'default',
        };
        $weights = self::WEIGHT_PROFILES[$profileName];
        $routingContext = [
            'model_tier'     => $modelTier,
            'task_class'     => $taskClass,
            'task_family'    => $taskFamily,
            'risk_level'     => $riskLevel,
            'weight_profile' => $profileName,
        ];
        $selectionReasons = ["weight_profile:{$profileName} (risk_level={$riskLevel})"];

        if ($variants === []) {
            return [
                'schema_version'       => self::SCHEMA,
                'model_tier'           => $modelTier,
                'task_class'           => $taskClass,
                'routing_context'      => $routingContext,
                'selected_variant'     => null,
                'exploration_variants' => [],
                'confidence'           => 'low',
                'evidence_counts'      => [],
                'rejected_variants'    => [],
                'retired_variants'     => [],
                'quarantined_variants' => [],
                'selection_reasons'    => $selectionReasons,
                'exploration_reason'   => null,
                'expected_quality_lift' => 0.0,
            ];
        }

        $scored              = [];
        $quarantinedVariants = [];
        $evidenceCounts      = [];

        // Total runs across all scored variants for UCB1 exploration
        $totalRuns = 0;
        foreach ($variants as $v) {
            $totalRuns += max(0, (int) ($v['total_runs'] ?? 0));
        }

        foreach ($variants as $v) {
            $id   = (string) ($v['variant_id'] ?? 'unknown');
            $runs = max(0, (int) ($v['total_runs'] ?? 0));

            $evidenceCounts[$id] = $runs;

            $proxyLeakRate = (float) ($v['proxy_leak_rate'] ?? 0.0);
            if ($proxyLeakRate > self::PROXY_LEAK_CEILING && $runs >= self::MIN_EVIDENCE) {
                $quarantinedVariants[] = $id;
                $selectionReasons[] = "quarantined:{$id} proxy_leak_rate={$proxyLeakRate} exceeds ceiling ".self::PROXY_LEAK_CEILING;
                continue;
            }

            $score        = $this->ucb($v, $runs, $totalRuns, $weights);
            $scored[$id]  = [
                'variant_id' => $id,
                'runs'       => $runs,
                'weighted'   => $score['weighted'],
                'ucb'        => $score['ucb'],
            ];
        }

        if ($scored === []) {
            return [
                'schema_version'       => self::SCHEMA,
                'model_tier'           => $modelTier,
                'task_class'           => $taskClass,
                'routing_context'      => $routingContext,
                'selected_variant'     => null,
                'exploration_variants' => [],
                'confidence'           => 'low',
                'evidence_counts'      => $evidenceCounts,
                'rejected_variants'    => [],
                'retired_variants'     => [],
                'quarantined_variants' => $quarantinedVariants,
                'selection_reasons'    => $selectionReasons,
                'exploration_reason'   => null,
                'expected_quality_lift' => 0.0,
            ];
        }

        uasort($scored, static function (array $a, array $b): int {
            $diff = $b['ucb'] <=> $a['ucb'];
            return $diff !== 0 ? $diff : $a['variant_id'] <=> $b['variant_id'];
        });

        $selected     = (string) array_key_first($scored);
        $selectedRuns = $scored[$selected]['runs'];

        $explorationVariants = [];
        $rejectedVariants    = [];
        $retiredVariants     = [];

        foreach ($scored as $id => $s) {
            if ($id !== $selected && $s['runs'] < self::MIN_EVIDENCE) {
                $explorationVariants[] = $id;
            }
            if ($s['runs'] >= self::MIN_EVIDENCE && $s['weighted'] < self::REJECTION_THRESHOLD) {
                $rejectedVariants[] = $id;
            }
            // Retired: variants with sufficient evidence that are both rejected AND quarantined
            // (i.e., confirmed poor performers that should no longer be considered)
        }

        // Retired = union of rejected and quarantined variants
        $retiredVariants = array_values(array_unique(array_merge($rejectedVariants, $quarantinedVariants)));

        if ($selectedRuns >= self::MIN_EVIDENCE * 2) {
            $confidence = 'high';
        } elseif ($selectedRuns >= self::MIN_EVIDENCE) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        $selectionReasons[] = "selected:{$selected} ucb={$scored[$selected]['ucb']} confidence={$confidence}";

        // AC4: explain WHY the winner won, and how much better it is than the field.
        $explorationReason = match (true) {
            $selectedRuns === 0 => 'unsampled_exploration_priority',
            $selectedRuns < self::MIN_EVIDENCE => 'low_evidence_best_available',
            default => 'proven_quality_leader',
        };

        $otherWeighted = [];
        foreach ($scored as $id => $s) {
            if ($id !== $selected) {
                $otherWeighted[] = $s['weighted'];
            }
        }
        $baseline = $otherWeighted !== [] ? array_sum($otherWeighted) / count($otherWeighted) : 0.0;
        $expectedQualityLift = round($scored[$selected]['weighted'] - $baseline, 4);

        return [
            'schema_version'       => self::SCHEMA,
            'model_tier'           => $modelTier,
            'task_class'           => $taskClass,
            'routing_context'      => $routingContext,
            'selected_variant'     => $selected,
            'exploration_variants' => $explorationVariants,
            'confidence'           => $confidence,
            'evidence_counts'      => $evidenceCounts,
            'rejected_variants'    => $rejectedVariants,
            'retired_variants'     => $retiredVariants,
            'quarantined_variants' => $quarantinedVariants,
            'selection_reasons'    => $selectionReasons,
            'exploration_reason'   => $explorationReason,
            'expected_quality_lift' => $expectedQualityLift,
        ];
    }

    /**
     * @param  array<string,mixed>  $v
     * @param  array<string,float>  $weights
     */
    private function ucb(array $v, int $runs, int $totalRuns, array $weights): array
    {
        if ($runs === 0) {
            $bonus = self::EXPLORATION_FACTOR * 2.0;
            return ['weighted' => 0.0, 'ucb' => round($bonus, 4)];
        }

        $giveBackRate    = max(0, (int) ($v['give_backs']         ?? 0)) / $runs;
        $successRate     = max(0, (int) ($v['successes']          ?? 0)) / $runs;
        $valueNorm       = min(1.0, (float) ($v['avg_value']      ?? 0) / 10.0);
        $heldoutPassRate = min(1.0, max(0.0, (float) ($v['heldout_pass_rate']  ?? 0.5)));
        $greenCommitRate = min(1.0, max(0.0, (float) ($v['green_commit_rate']  ?? 0.5)));
        $avgCost         = max(0.0, (float) ($v['avg_cost'] ?? 5.0));
        $costScore       = 1.0 - min(1.0, $avgCost / self::MAX_AVG_COST);

        $overfitGap = max(0.0, $successRate - $heldoutPassRate);
        $overfitPenalty = self::OVERFIT_PENALTY_WEIGHT * $overfitGap;

        $weighted = round(
            max(0.0, $successRate     * $weights['success']
            + $heldoutPassRate * $weights['heldout']
            + $valueNorm       * $weights['value']
            + $greenCommitRate * $weights['green']
            + (1.0 - $giveBackRate) * $weights['give_back']
            + $costScore       * $weights['cost']
            - $overfitPenalty),
            4,
        );

        // True UCB1: exploration = C * sqrt(2*ln(N) / n)
        // where N = total runs across all variants, n = runs for this variant.
        // Never reaches 0, so under-pulled variants stay in contention as N grows.
        $exploration = $totalRuns > 0
            ? round(self::EXPLORATION_FACTOR * sqrt(2.0 * log((float) $totalRuns) / (float) $runs), 4)
            : 0.0;

        return ['weighted' => $weighted, 'ucb' => min(1.0, round($weighted + $exploration, 4))];
    }
}
