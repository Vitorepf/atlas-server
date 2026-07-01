<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure evaluator. Measures whether a batch-generation policy increases the
 * ratio of task specs that become successful green commits, weighted by task
 * impact, rather than merely inflating queue volume.
 *
 * Input: before[] and after[] arrays of outcome rows.
 * Each row: { status: 'green_commit'|'give_back'|'failed'|..., impact_weight: float 0-1 }
 *
 * Output:
 *   green_commit_rate_delta   — after_green_rate - before_green_rate
 *   give_back_rate_delta      — after_give_back_rate - before_give_back_rate
 *   impact_weighted_lift      — after_weighted_green - before_weighted_green
 *   verdict                   — improvement | regression | neutral
 *
 * REGRESSION fires when:
 *   (a) green commits rise but impact_weighted_lift <= 0  (low-impact-only improvement)
 *   (b) give_back_rate_delta > GIVE_BACK_THRESHOLD (0.05) (give_back worsened materially)
 *
 * IMPROVEMENT fires when green_commit_rate_delta > 0 AND impact_weighted_lift > 0
 * AND neither regression condition is met.
 *
 * NEUTRAL otherwise.
 *
 * Pure: no I/O, no side effects, no provider calls.
 */
final class AtlasExternalBrainCommitGreenLiftEvaluator
{
    public const SCHEMA = 'atlas.external_brain.commit_green_lift_evaluator.v1';

    public const VERDICT_IMPROVEMENT = 'improvement';
    public const VERDICT_REGRESSION  = 'regression';
    public const VERDICT_NEUTRAL     = 'neutral';

    private const STATUS_GREEN_COMMIT = 'green_commit';
    private const STATUS_GIVE_BACK    = 'give_back';
    private const GIVE_BACK_THRESHOLD = 0.05;

    public const COMMIT_VERDICT_REAL_VALUE_LIFT = 'real_value_lift';
    public const COMMIT_VERDICT_LOW_LIFT        = 'low_lift_cosmetic';

    public const GREENWASHING_TEST_ONLY      = 'test_only_no_implementation_change';
    public const GREENWASHING_COSMETIC       = 'cosmetic_no_lift_signal';
    public const GREENWASHING_PROXY_ONLY     = 'proxy_evidence_only';
    public const GREENWASHING_STALE_EVIDENCE = 'stale_evidence';

    private const DEFAULT_MAX_EVIDENCE_AGE_SECONDS = 86400;

    /**
     * Evaluates a SINGLE green commit for real capability lift, downstream
     * unlock, simplification, or risk reduction — a green test suite alone
     * is never counted as value. A commit with none of these four signals
     * is a cosmetic green and is marked low_lift, not success amplification.
     *
     * @param  array<string,mixed>  $commit
     * @return array<string,mixed>
     */
    public function evaluateCommit(array $commit): array
    {
        $capabilityLiftEvidence = array_values((array) ($commit['capability_lift_evidence'] ?? []));
        $downstreamUnlocks = array_values((array) ($commit['downstream_unlocks'] ?? []));
        $simplificationDelta = (float) ($commit['simplification_delta'] ?? 0.0);
        $riskReductionEvidence = array_values((array) ($commit['risk_reduction_evidence'] ?? []));

        $hasCapabilityLift = $capabilityLiftEvidence !== [];
        $hasDownstreamUnlock = $downstreamUnlocks !== [];
        $hasSimplification = $simplificationDelta > 0.0;
        $hasRiskReduction = $riskReductionEvidence !== [];

        $signalCount = (int) $hasCapabilityLift + (int) $hasDownstreamUnlock + (int) $hasSimplification + (int) $hasRiskReduction;

        // AC1/AC2: a green test suite alone is never lift — implementation_change and
        // relevant_tests default to true (assume a normal code commit) so existing callers that
        // never set them are unaffected; a commit that explicitly declares no implementation
        // change is test-only greenwashing regardless of how many "signals" it claims.
        $implementationChange = (bool) ($commit['implementation_change'] ?? true);
        $relevantTests = (bool) ($commit['relevant_tests'] ?? true);
        $proxyEvidenceOnly = (bool) ($commit['proxy_evidence_only'] ?? false);
        $evidenceAgeSeconds = isset($commit['evidence_age_seconds']) ? (int) $commit['evidence_age_seconds'] : null;
        $maxEvidenceAgeSeconds = (int) ($commit['max_evidence_age_seconds'] ?? self::DEFAULT_MAX_EVIDENCE_AGE_SECONDS);
        $evidenceStale = $evidenceAgeSeconds !== null && $evidenceAgeSeconds > $maxEvidenceAgeSeconds;

        $greenwashingReason = match (true) {
            ! $implementationChange && $relevantTests => self::GREENWASHING_TEST_ONLY,
            $proxyEvidenceOnly => self::GREENWASHING_PROXY_ONLY,
            $evidenceStale => self::GREENWASHING_STALE_EVIDENCE,
            $signalCount === 0 => self::GREENWASHING_COSMETIC,
            default => null,
        };
        $isGreenwashing = $greenwashingReason !== null;
        $isLowLift = $isGreenwashing;
        $verdict = $isLowLift ? self::COMMIT_VERDICT_LOW_LIFT : self::COMMIT_VERDICT_REAL_VALUE_LIFT;

        return [
            'schema'                  => self::SCHEMA,
            'capability_lift'         => $hasCapabilityLift,
            'downstream_unlocks'      => $downstreamUnlocks,
            'downstream_unlock_count' => count($downstreamUnlocks),
            'simplification_delta'    => round($simplificationDelta, 4),
            'risk_reduction_evidence' => $riskReductionEvidence,
            'signal_count'            => $signalCount,
            'implementation_change'   => $implementationChange,
            'relevant_tests'          => $relevantTests,
            'proxy_evidence_only'     => $proxyEvidenceOnly,
            'evidence_stale'          => $evidenceStale,
            'is_greenwashing'         => $isGreenwashing,
            'greenwashing_reason'     => $greenwashingReason,
            'is_low_lift'             => $isLowLift,
            'verdict'                 => $verdict,
            'learning_feedback'       => $this->commitLearningFeedback(
                verdict: $verdict,
                hasCapabilityLift: $hasCapabilityLift,
                hasDownstreamUnlock: $hasDownstreamUnlock,
                hasSimplification: $hasSimplification,
                hasRiskReduction: $hasRiskReduction,
            ),
        ];
    }

    /** @return list<string> */
    private function commitLearningFeedback(
        string $verdict,
        bool $hasCapabilityLift,
        bool $hasDownstreamUnlock,
        bool $hasSimplification,
        bool $hasRiskReduction,
    ): array {
        if ($verdict === self::COMMIT_VERDICT_LOW_LIFT) {
            return [
                'do_not_credit_green_tests_alone_as_value',
                'avoid_authoring_more_cosmetic_only_tasks_in_this_theme',
                'next_cycle_require_capability_lift_unlock_simplification_or_risk_reduction_evidence',
            ];
        }

        return array_values(array_filter([
            $hasCapabilityLift ? 'capability_lift_confirmed_author_more_in_this_family' : null,
            $hasDownstreamUnlock ? 'downstream_tasks_unlocked_prioritize_them_next_cycle' : null,
            $hasSimplification ? 'simplification_reduced_complexity_reward_this_pattern' : null,
            $hasRiskReduction ? 'risk_reduction_evidenced_reinforce_this_safety_pattern' : null,
        ]));
    }

    /**
     * @param  array{before?: list<array<string,mixed>>, after?: list<array<string,mixed>>}  $input
     * @return array{schema:string, green_commit_rate_delta:float, give_back_rate_delta:float, impact_weighted_lift:float, verdict:string, before_stats:array<string,mixed>, after_stats:array<string,mixed>}
     */
    public function evaluate(array $input): array
    {
        $before = is_array($input['before'] ?? null) ? $input['before'] : [];
        $after  = is_array($input['after']  ?? null) ? $input['after']  : [];

        $beforeStats = $this->computeStats($before);
        $afterStats  = $this->computeStats($after);

        $greenDelta    = $afterStats['green_rate']           - $beforeStats['green_rate'];
        $giveBackDelta = $afterStats['give_back_rate']       - $beforeStats['give_back_rate'];
        $impactLift    = $afterStats['impact_weighted_green'] - $beforeStats['impact_weighted_green'];

        return [
            'schema'                  => self::SCHEMA,
            'green_commit_rate_delta' => round($greenDelta,    4),
            'give_back_rate_delta'    => round($giveBackDelta, 4),
            'impact_weighted_lift'    => round($impactLift,    4),
            'verdict'                 => $this->verdict($greenDelta, $giveBackDelta, $impactLift),
            'before_stats'            => $beforeStats,
            'after_stats'             => $afterStats,
        ];
    }

    /** @return array<string,mixed> */
    private function computeStats(array $rows): array
    {
        $total         = count($rows);
        $greenCount    = 0;
        $giveBackCount = 0;
        $weightedGreen = 0.0;

        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row['status']        ?? '')));
            $weight = max(0.0, min(1.0, (float) ($row['impact_weight'] ?? 0.5)));

            if ($status === self::STATUS_GREEN_COMMIT) {
                $greenCount++;
                $weightedGreen += $weight;
            }
            if ($status === self::STATUS_GIVE_BACK) {
                $giveBackCount++;
            }
        }

        $greenRate             = $total > 0 ? $greenCount    / $total : 0.0;
        $giveBackRate          = $total > 0 ? $giveBackCount / $total : 0.0;
        $impactWeightedGreen   = $total > 0 ? $weightedGreen / $total : 0.0;

        return [
            'total'                 => $total,
            'green_count'           => $greenCount,
            'give_back_count'       => $giveBackCount,
            'green_rate'            => round($greenRate,           4),
            'give_back_rate'        => round($giveBackRate,        4),
            'impact_weighted_green' => round($impactWeightedGreen, 4),
        ];
    }

    private function verdict(float $greenDelta, float $giveBackDelta, float $impactLift): string
    {
        // Regression (a): green commits rise only via low-impact tasks
        if ($greenDelta > 0 && $impactLift <= 0) {
            return self::VERDICT_REGRESSION;
        }

        // Regression (b): give_back worsened materially
        if ($giveBackDelta > self::GIVE_BACK_THRESHOLD) {
            return self::VERDICT_REGRESSION;
        }

        // Improvement: green rate up AND impact-weighted lift positive
        if ($greenDelta > 0 && $impactLift > 0) {
            return self::VERDICT_IMPROVEMENT;
        }

        return self::VERDICT_NEUTRAL;
    }
}
