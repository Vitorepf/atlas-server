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

    private const MAX_AVG_COST = 10.0;

    /**
     * @param  array<string,mixed>  $input  model_tier, task_class, variant_outcomes
     * @return array<string,mixed>
     */
    public function select(array $input): array
    {
        $modelTier = (string) ($input['model_tier'] ?? '');
        $taskClass = (string) ($input['task_class'] ?? '');
        $variants  = is_array($input['variant_outcomes'] ?? null) ? $input['variant_outcomes'] : [];

        if ($variants === []) {
            return [
                'schema_version'       => self::SCHEMA,
                'model_tier'           => $modelTier,
                'task_class'           => $taskClass,
                'selected_variant'     => null,
                'exploration_variants' => [],
                'confidence'           => 'low',
                'evidence_counts'      => [],
                'rejected_variants'    => [],
                'quarantined_variants' => [],
            ];
        }

        $scored              = [];
        $quarantinedVariants = [];
        $evidenceCounts      = [];

        foreach ($variants as $v) {
            $id   = (string) ($v['variant_id'] ?? 'unknown');
            $runs = max(0, (int) ($v['total_runs'] ?? 0));

            $evidenceCounts[$id] = $runs;

            $proxyLeakRate = (float) ($v['proxy_leak_rate'] ?? 0.0);
            if ($proxyLeakRate > self::PROXY_LEAK_CEILING && $runs >= self::MIN_EVIDENCE) {
                $quarantinedVariants[] = $id;
                continue;
            }

            $score        = $this->ucb($v, $runs);
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
                'selected_variant'     => null,
                'exploration_variants' => [],
                'confidence'           => 'low',
                'evidence_counts'      => $evidenceCounts,
                'rejected_variants'    => [],
                'quarantined_variants' => $quarantinedVariants,
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

        foreach ($scored as $id => $s) {
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
            'schema_version'       => self::SCHEMA,
            'model_tier'           => $modelTier,
            'task_class'           => $taskClass,
            'selected_variant'     => $selected,
            'exploration_variants' => $explorationVariants,
            'confidence'           => $confidence,
            'evidence_counts'      => $evidenceCounts,
            'rejected_variants'    => $rejectedVariants,
            'quarantined_variants' => $quarantinedVariants,
        ];
    }

    /** @param array<string,mixed> $v */
    private function ucb(array $v, int $runs): array
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

        $weighted = round(
            $successRate     * 0.40
            + $heldoutPassRate * 0.20
            + $valueNorm       * 0.15
            + $greenCommitRate * 0.10
            + (1.0 - $giveBackRate) * 0.10
            + $costScore       * 0.05,
            4,
        );

        $exploration = $runs < self::MIN_EVIDENCE
            ? round(self::EXPLORATION_FACTOR * (1.0 - $runs / self::MIN_EVIDENCE), 4)
            : 0.0;

        return ['weighted' => $weighted, 'ucb' => min(1.0, round($weighted + $exploration, 4))];
    }
}
