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

    public const MIN_SHADOW_RUNS = 30;

    public const MIN_LIFT = 0.10;

    public const MAX_GIVE_BACK_DELTA = 0.05;

    public const MAX_POISON_DELTA = 0.0;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $shadowRuns = max(0, (int) ($input['shadow_runs'] ?? 0));
        $liftRatio = (float) ($input['sustained_lift_ratio'] ?? 0.0);
        $slo = (bool) ($input['slo_passed'] ?? false);
        $replayCourt = (bool) ($input['replay_court_passed'] ?? false);
        $scaffoldCompliance = (bool) ($input['scaffold_compliance'] ?? false);
        $overfitDetected = (bool) ($input['overfit_detected'] ?? false);
        $giveBackDelta = (float) ($input['give_back_delta'] ?? 0.0);
        $poisonDelta = (float) ($input['poison_delta'] ?? 0.0);

        $blockingReasons = [];

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

        if ($poisonDelta > self::MAX_POISON_DELTA) {
            $blockingReasons[] = 'poison_risk_increased';
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

        $promote = $blockingReasons === [];
        $requiredMoreShadowRuns = max(0, self::MIN_SHADOW_RUNS - $shadowRuns);

        $liveRolloutConstraints = $promote
            ? ['canary_first', 'monitor_give_backs_daily', 'slo_recheck_after_100_runs']
            : [];

        return [
            'schema_version' => self::SCHEMA,
            'promote' => $promote,
            'blocking_reasons' => $blockingReasons,
            'required_more_shadow_runs' => $requiredMoreShadowRuns,
            'live_rollout_constraints' => $liveRolloutConstraints,
        ];
    }
}
