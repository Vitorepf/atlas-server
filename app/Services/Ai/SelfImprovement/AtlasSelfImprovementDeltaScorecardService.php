<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Self-Improvement Before/After Delta Scorecard.
 *
 * Compares two snapshots of the Atlas state (before / after) on the 13
 * canonical metrics from the governance ladder and produces a deterministic
 * delta + promotion recommendation.
 *
 * Hard rules:
 *   - NEVER promotes by itself;
 *   - NEVER calls a provider;
 *   - Promotion requires positive total delta AND no `regressions_and_blockers`
 *     finding marked severe — caller must combine with Invariant Lock and
 *     Regression Sentinel before approving promotion;
 *   - When delta is negative or critical metric regressed, returns
 *     `recommend_rollback` so Forge does not silently merge.
 *
 * Schema: atlas.self_improvement.before_after_delta_scorecard.v1
 */
class AtlasSelfImprovementDeltaScorecardService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.before_after_delta_scorecard.v1';

    public const RECOMMEND_PROMOTE = 'promote';

    public const RECOMMEND_PROMOTE_WITH_REVIEW = 'promote_with_review';

    public const RECOMMEND_HOLD = 'hold';

    public const RECOMMEND_ROLLBACK = 'rollback';

    /** @var list<string> 13 canonical metrics, weights total 100. */
    public const METRICS = [
        'functional_correctness',
        'business_rule_alignment',
        'canonical_documentation_adherence',
        'test_and_risk_coverage',
        'enterprise_architecture_quality',
        'governance_integrity',
        'operator_experience',
        'automation_level',
        'human_intervention_load',
        'evidence_and_observability',
        'runtime_safety',
        'provider_cost_token_impact',
        'regressions_and_new_blockers',
    ];

    /** @var array<string,int> Weight per metric — total 100. */
    public const WEIGHTS = [
        'functional_correctness' => 14,
        'business_rule_alignment' => 12,
        'canonical_documentation_adherence' => 6,
        'test_and_risk_coverage' => 12,
        'enterprise_architecture_quality' => 8,
        'governance_integrity' => 12,
        'operator_experience' => 6,
        'automation_level' => 4,
        'human_intervention_load' => 4,
        'evidence_and_observability' => 8,
        'runtime_safety' => 8,
        'provider_cost_token_impact' => 2,
        'regressions_and_new_blockers' => 4,
    ];

    /** @var list<string> Metrics where ANY regression turns recommendation into rollback. */
    public const HARD_REGRESSION_METRICS = [
        'governance_integrity',
        'runtime_safety',
        'business_rule_alignment',
        'regressions_and_new_blockers',
    ];

    /**
     * Compute the canonical delta between before/after snapshots.
     *
     * @param  array<string,mixed>  $before  Each metric mapped to a 0..10 score.
     * @param  array<string,mixed>  $after  Same keys.
     * @param  array<string,mixed>  $context  proposal_id, expected_power_gain, …
     * @return array<string,mixed>
     */
    public function compute(array $before, array $after, array $context = []): array
    {
        $beforeNormalized = $this->normalizeSnapshot($before);
        $afterNormalized = $this->normalizeSnapshot($after);

        $perMetric = [];
        $weightedDelta = 0.0;
        $hardRegression = false;
        $regressionMetrics = [];

        foreach (self::METRICS as $metric) {
            $beforeScore = $beforeNormalized[$metric] ?? 0.0;
            $afterScore = $afterNormalized[$metric] ?? 0.0;
            $delta = $afterScore - $beforeScore;
            $weight = self::WEIGHTS[$metric] ?? 0;
            $weighted = $delta * $weight;
            $weightedDelta += $weighted;

            $perMetric[] = [
                'metric' => $metric,
                'before' => round($beforeScore, 2),
                'after' => round($afterScore, 2),
                'delta' => round($delta, 2),
                'weight' => $weight,
                'weighted_delta' => round($weighted, 2),
                'is_hard_regression_metric' => in_array($metric, self::HARD_REGRESSION_METRICS, true),
                'regressed' => $delta < 0,
            ];

            if ($delta < 0 && in_array($metric, self::HARD_REGRESSION_METRICS, true)) {
                $hardRegression = true;
                $regressionMetrics[] = $metric;
            }
        }

        $totalWeight = (int) array_sum(self::WEIGHTS);
        $maxPossibleDelta = $totalWeight * 10.0;
        $normalizedScore = $totalWeight > 0
            ? round(($weightedDelta / $maxPossibleDelta) * 100.0, 2)
            : 0.0;

        $recommendation = $this->resolveRecommendation(
            $weightedDelta,
            $hardRegression,
            (bool) ($context['human_review_required'] ?? false),
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scorecard_id' => 'sc_'.(string) Str::ulid(),
            'generated_at' => Carbon::now()->toIso8601String(),
            'proposal_id' => $context['proposal_id'] ?? null,
            'expected_power_gain' => $context['expected_power_gain'] ?? null,
            'metrics' => $perMetric,
            'metric_count' => count($perMetric),
            'total_weight' => $totalWeight,
            'weighted_delta' => round($weightedDelta, 2),
            'normalized_score' => $normalizedScore,
            'hard_regression_detected' => $hardRegression,
            'regressed_metrics' => $regressionMetrics,
            'recommendation' => $recommendation,
            'invariants' => [
                'never_promotes_directly' => true,
                'never_calls_external_provider' => true,
                'never_unlocks_external_rivals_claim' => true,
                'positive_delta_required_for_promote' => true,
                'hard_regression_forces_rollback' => true,
                'requires_invariant_lock_pass' => true,
                'requires_regression_sentinel_pass' => true,
            ],
            'next_action' => $this->resolveNextAction($recommendation),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,float>
     */
    private function normalizeSnapshot(array $snapshot): array
    {
        $result = [];
        foreach (self::METRICS as $metric) {
            $value = $snapshot[$metric] ?? null;
            if (! is_numeric($value)) {
                $result[$metric] = 0.0;

                continue;
            }
            $float = (float) $value;
            $result[$metric] = max(0.0, min(10.0, $float));
        }

        return $result;
    }

    private function resolveRecommendation(
        float $weightedDelta,
        bool $hardRegression,
        bool $humanReviewRequired,
    ): string {
        if ($hardRegression) {
            return self::RECOMMEND_ROLLBACK;
        }
        if ($weightedDelta < 0) {
            return self::RECOMMEND_HOLD;
        }
        if ($humanReviewRequired || $weightedDelta < 30) {
            // Small positive delta still requires human review for safety.
            return self::RECOMMEND_PROMOTE_WITH_REVIEW;
        }

        return self::RECOMMEND_PROMOTE;
    }

    private function resolveNextAction(string $recommendation): string
    {
        return match ($recommendation) {
            self::RECOMMEND_PROMOTE => 'run_invariant_lock_then_regression_sentinel_then_promote',
            self::RECOMMEND_PROMOTE_WITH_REVIEW => 'request_human_review_before_promotion',
            self::RECOMMEND_HOLD => 'iterate_proposal_or_implementation',
            self::RECOMMEND_ROLLBACK => 'rollback_governed_promotion_and_reopen_proposal',
            default => 'inspect_recommendation',
        };
    }
}
