<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure promotion gate: a scaffold/model-amplifier variant can move from shadow
 * to live task origination only when all six conditions are met.
 *
 * Blocking conditions (any one → promote=false):
 *   1. shadow_runs < MIN_SHADOW_RUNS          — sample too small
 *   2. sustained_lift_ratio < MIN_LIFT        — lift not sustained
 *   3. overfit_detected = true                — benchmark overfitting
 *   4. give_back_delta > MAX_GIVE_BACK_DELTA  — give_back rate worsened
 *   5. poison_delta > MAX_POISON_DELTA        — any increase in poison
 *   6. slo_passed=false OR replay_court_passed=false OR scaffold_compliance=false
 *
 * required_more_shadow_runs: max(0, MIN_SHADOW_RUNS − shadow_runs).
 * live_rollout_constraints: emitted only when gate passes; includes canary
 *   requirement and daily SLO recheck.
 */
final class AtlasExternalBrainAmplifierPromotionGate
{
    public const SCHEMA = 'atlas.external_brain.amplifier_promotion_gate.v1';

    public const DECISION_PROMOTE     = 'promote';
    public const DECISION_SHADOW_MORE = 'shadow_more';
    public const DECISION_ROLLBACK    = 'rollback';

    public const MIN_SHADOW_RUNS = 30;

    public const MIN_LIFT = 0.10;

    public const MAX_GIVE_BACK_DELTA = 0.05;

    public const MAX_POISON_DELTA = 0.0;

    // New held-out / quality gate thresholds.
    public const MIN_HELDOUT_PASS_RATE  = 0.80;
    public const MIN_GREEN_COMMIT_RATE  = 0.90;
    public const MAX_PROXY_LEAK_RATE    = 0.10;
    public const MIN_SAMPLE_COUNT       = 50;
    public const MIN_QUALITY_LIFT_DELTA = 0.05;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        // Legacy inputs.
        $shadowRuns         = max(0, (int) ($input['shadow_runs']            ?? 0));
        $liftRatio          = (float) ($input['sustained_lift_ratio']        ?? 0.0);
        $slo                = (bool) ($input['slo_passed']                   ?? false);
        $replayCourt        = (bool) ($input['replay_court_passed']          ?? false);
        $scaffoldCompliance = (bool) ($input['scaffold_compliance']          ?? false);
        $overfitDetected    = (bool) ($input['overfit_detected']             ?? false);
        $giveBackDelta      = (float) ($input['give_back_delta']             ?? 0.0);
        $poisonDelta        = (float) ($input['poison_delta']                ?? 0.0);

        // New held-out / quality gate inputs.
        $heldoutPassRate  = max(0.0, min(1.0, (float) ($input['heldout_pass_rate']  ?? 0.0)));
        $greenCommitRate  = max(0.0, min(1.0, (float) ($input['green_commit_rate']  ?? 0.0)));
        $proxyLeakRate    = max(0.0, min(1.0, (float) ($input['proxy_leak_rate']    ?? 0.0)));
        $sampleCount      = max(0, (int) ($input['sample_count']                    ?? 0));
        $qualityLiftDelta = (float) ($input['quality_lift_delta']                   ?? 0.0);

        $blockingReasons  = [];
        $missingEvidence  = [];
        $rollbackTriggers = [];

        // ── Rollback triggers (immediate block) ──────────────────────────────
        if ($proxyLeakRate > self::MAX_PROXY_LEAK_RATE) {
            $rollbackTriggers[] = 'proxy_leak_ceiling_breached';
            $missingEvidence[]  = 'proxy_audit_showing_leak_rate_below_ceiling';
        }
        if ($qualityLiftDelta < 0.0) {
            $rollbackTriggers[] = 'quality_regression_detected';
            $missingEvidence[]  = 'quality_lift_evidence_showing_no_regression';
        }
        if ($poisonDelta > self::MAX_POISON_DELTA) {
            $rollbackTriggers[] = 'poison_risk_increased';
            $missingEvidence[]  = 'poison_rate_audit_showing_no_increase';
        }

        // ── Shadow-more conditions ───────────────────────────────────────────
        if ($heldoutPassRate < self::MIN_HELDOUT_PASS_RATE) {
            $blockingReasons[] = 'heldout_pass_rate_below_floor';
            $missingEvidence[] = 'heldout_test_suite_with_pass_rate_above_floor';
        }
        if ($greenCommitRate < self::MIN_GREEN_COMMIT_RATE) {
            $blockingReasons[] = 'green_commit_rate_below_floor';
            $missingEvidence[] = 'green_commit_evidence_for_last_50_runs';
        }
        if ($sampleCount < self::MIN_SAMPLE_COUNT) {
            $blockingReasons[] = 'sample_count_insufficient';
            $missingEvidence[] = (self::MIN_SAMPLE_COUNT - $sampleCount).'_more_shadow_samples';
        }
        if ($qualityLiftDelta < self::MIN_QUALITY_LIFT_DELTA && $qualityLiftDelta >= 0.0) {
            $blockingReasons[] = 'quality_lift_delta_below_floor';
            $missingEvidence[] = 'quality_lift_evidence_above_delta_floor';
        }

        // ── Legacy conditions ────────────────────────────────────────────────
        if ($shadowRuns < self::MIN_SHADOW_RUNS) {
            $blockingReasons[] = 'sample_too_small';
        }
        if ($liftRatio < self::MIN_LIFT) {
            $blockingReasons[] = 'lift_not_sustained';
        }
        if ($overfitDetected) {
            $blockingReasons[] = 'overfit_detected';
        }
        if ($giveBackDelta > self::MAX_GIVE_BACK_DELTA) {
            $blockingReasons[] = 'give_back_risk_increased';
        }
        if (! $slo) {
            $blockingReasons[] = 'slo_not_passed';
        }
        if (! $replayCourt) {
            $blockingReasons[] = 'replay_court_not_passed';
        }
        if (! $scaffoldCompliance) {
            $blockingReasons[] = 'scaffold_compliance_missing';
        }

        // ── Decision ─────────────────────────────────────────────────────────
        $promote  = $rollbackTriggers === [] && $blockingReasons === [];
        $decision = match (true) {
            $promote              => self::DECISION_PROMOTE,
            $rollbackTriggers !== [] => self::DECISION_ROLLBACK,
            default               => self::DECISION_SHADOW_MORE,
        };

        $reasons = array_values(array_merge($rollbackTriggers, $blockingReasons));
        $requiredMoreShadowRuns = max(0, self::MIN_SHADOW_RUNS - $shadowRuns);

        $liveRolloutConstraints = $promote
            ? ['canary_first', 'monitor_give_backs_daily', 'slo_recheck_after_100_runs']
            : [];

        return [
            'schema_version'            => self::SCHEMA,
            'decision'                  => $decision,
            'promote'                   => $promote,
            'reasons'                   => $reasons,
            'missing_evidence'          => array_values(array_unique($missingEvidence)),
            'blocking_reasons'          => $blockingReasons,
            'required_more_shadow_runs' => $requiredMoreShadowRuns,
            'live_rollout_constraints'  => $liveRolloutConstraints,
        ];
    }
}
