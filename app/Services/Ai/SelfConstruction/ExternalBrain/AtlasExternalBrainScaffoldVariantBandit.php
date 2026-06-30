<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure bandit: ranks scaffold variants by weighted historical outcomes and
 * adds an exploration bonus for under-sampled variants, scoped per model_tier
 * and task_class. Operates on pre-filtered variant_outcomes — no live I/O.
 *
 * UCB score = weighted_outcome + exploration_bonus
 *   weighted_outcome = success_rate*0.60 + value_norm*0.25 + (1−give_back_rate)*0.15
 *   exploration_bonus = EXPLORATION_FACTOR*(1−runs/MIN_EVIDENCE) when runs < MIN_EVIDENCE
 *   Unsampled variants receive bonus = EXPLORATION_FACTOR*2 so they are explored first.
 *
 * selected_variant: highest UCB score (variant_id used as tie-breaker).
 * exploration_variants: all variants with total_runs < MIN_EVIDENCE (except selected).
 * confidence: high≥MIN_EVIDENCE*2 runs, medium≥MIN_EVIDENCE, low otherwise.
 * evidence_counts: {variant_id → total_runs} for all variants.
 * rejected_variants: variants with weighted_outcome < REJECTION_THRESHOLD AND
 *   total_runs ≥ MIN_EVIDENCE (evidence sufficient to call them poor performers).
 */
final class AtlasExternalBrainScaffoldVariantBandit
{
    public const SCHEMA = 'atlas.external_brain.scaffold_variant_bandit.v1';

    public const MIN_EVIDENCE = 5;

    public const EXPLORATION_FACTOR = 0.3;

    public const REJECTION_THRESHOLD = 0.30;

    /**
     * @param  array<string,mixed>  $input  model_tier, task_class, variant_outcomes
     * @return array<string,mixed>
     */
    public function select(array $input): array
    {
        $modelTier = (string) ($input['model_tier'] ?? '');
        $taskClass = (string) ($input['task_class'] ?? '');
        $variants = is_array($input['variant_outcomes'] ?? null) ? $input['variant_outcomes'] : [];

        if ($variants === []) {
            return [
                'schema_version' => self::SCHEMA,
                'model_tier' => $modelTier,
                'task_class' => $taskClass,
                'selected_variant' => null,
                'exploration_variants' => [],
                'confidence' => 'low',
                'evidence_counts' => [],
                'rejected_variants' => [],
            ];
        }

        $scored = [];
        foreach ($variants as $v) {
            $id = (string) ($v['variant_id'] ?? 'unknown');
            $runs = max(0, (int) ($v['total_runs'] ?? 0));
            $score = $this->ucb($v, $runs);
            $scored[$id] = [
                'variant_id' => $id,
                'runs' => $runs,
                'weighted' => $score['weighted'],
                'ucb' => $score['ucb'],
            ];
        }

        uasort($scored, static function (array $a, array $b): int {
            $diff = $b['ucb'] <=> $a['ucb'];

            return $diff !== 0 ? $diff : $a['variant_id'] <=> $b['variant_id'];
        });

        $selected = array_key_first($scored);
        $selectedRuns = $scored[$selected]['runs'];

        $explorationVariants = [];
        $rejectedVariants = [];
        $evidenceCounts = [];

        foreach ($scored as $id => $s) {
            $evidenceCounts[$id] = $s['runs'];
            if ($id !== $selected && $s['runs'] < self::MIN_EVIDENCE) {
                $explorationVariants[] = $id;
            }
            if ($s['runs'] >= self::MIN_EVIDENCE && $s['weighted'] < self::REJECTION_THRESHOLD) {
                $rejectedVariants[] = $id;
            }
        }

        if ($selectedRuns >= self::MIN_EVIDENCE * 2) {
            $confidence = 'high';
        } elseif ($selectedRuns >= self::MIN_EVIDENCE) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        return [
            'schema_version' => self::SCHEMA,
            'model_tier' => $modelTier,
            'task_class' => $taskClass,
            'selected_variant' => $selected,
            'exploration_variants' => $explorationVariants,
            'confidence' => $confidence,
            'evidence_counts' => $evidenceCounts,
            'rejected_variants' => $rejectedVariants,
        ];
    }

    /** @param array<string,mixed> $v */
    private function ucb(array $v, int $runs): array
    {
        if ($runs === 0) {
            $bonus = self::EXPLORATION_FACTOR * 2.0;

            return ['weighted' => 0.0, 'ucb' => round($bonus, 4)];
        }

        $giveBackRate = max(0, (int) ($v['give_backs'] ?? 0)) / $runs;
        $successRate = max(0, (int) ($v['successes'] ?? 0)) / $runs;
        $valueNorm = min(1.0, (float) ($v['avg_value'] ?? 0) / 10.0);

        $weighted = round($successRate * 0.60 + $valueNorm * 0.25 + (1.0 - $giveBackRate) * 0.15, 4);
        $exploration = $runs < self::MIN_EVIDENCE
            ? round(self::EXPLORATION_FACTOR * (1.0 - $runs / self::MIN_EVIDENCE), 4)
            : 0.0;

        return ['weighted' => $weighted, 'ucb' => min(1.0, round($weighted + $exploration, 4))];
    }
}
