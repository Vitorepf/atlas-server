<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure canary kill-switch evaluator. Checks live canary metrics against
 * hard ceilings and recommends rollback, continue, or wait for more samples.
 *
 * AC2: rollback when any metric breaches its ceiling.
 * AC3: continue only when all metrics are within thresholds AND sample size ≥ MIN.
 *      Insufficient sample with no breach → wait_for_sample.
 * AC4: output always includes action, breached_thresholds, rollback_scope,
 *      sample_size, and next_safe_variant.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAmplifierCanaryKillSwitch
{
    public const SCHEMA = 'atlas.external_brain.amplifier_canary_kill_switch.v1';

    public const ACTION_ROLLBACK          = 'rollback';
    public const ACTION_CONTINUE          = 'continue';
    public const ACTION_WAIT_FOR_SAMPLE   = 'wait_for_sample';

    private const MIN_SAMPLE_SIZE         = 10;

    // Hard ceilings (inclusive breach at >)
    private const DUPLICATE_CEILING       = 0.20;
    private const GIVE_BACK_CEILING       = 0.25;
    private const MALFORMED_CEILING       = 0.10;
    private const WEAK_EVIDENCE_CEILING   = 0.30;
    private const LOW_VALUE_CEILING       = 0.30;

    // Kill-switch thresholds
    private const PROXY_LEAK_CEILING      = 0.15;
    private const FAILURE_STREAK_LIMIT    = 3;

    /**
     * @param  array{
     *   sample_size?: int,
     *   duplicate_rate?: float,
     *   give_back_rate?: float,
     *   malformed_rate?: float,
     *   weak_evidence_rate?: float,
     *   low_value_rate?: float,
     *   rollback_telemetry?: bool,
     *   proxy_leak_rate?: float,
     *   held_out_failure_streak?: int,
     *   regression_spike?: bool,
     *   has_mandatory_telemetry?: bool,
     * }  $input
     * @return array{schema:string, action:string, breached_thresholds:list<string>, rollback_scope:string|null, sample_size:int, next_safe_variant:string, kill_switch_active:bool, kill_reason:string|null, recovery_condition:string, safe_mode_policy:string}
     */
    public function evaluate(array $input): array
    {
        $sampleSize       = max(0, (int) ($input['sample_size']        ?? 0));
        $duplicateRate    = max(0.0, min(1.0, (float) ($input['duplicate_rate']     ?? 0.0)));
        $giveBackRate     = max(0.0, min(1.0, (float) ($input['give_back_rate']     ?? 0.0)));
        $malformedRate    = max(0.0, min(1.0, (float) ($input['malformed_rate']     ?? 0.0)));
        $weakEvidenceRate = max(0.0, min(1.0, (float) ($input['weak_evidence_rate'] ?? 0.0)));
        $lowValueRate     = max(0.0, min(1.0, (float) ($input['low_value_rate']     ?? 0.0)));

        // Kill-switch triggers (evaluated in priority order).
        $rollbackTelemetry    = (bool) ($input['rollback_telemetry']         ?? false);
        $proxyLeakRate        = max(0.0, min(1.0, (float) ($input['proxy_leak_rate'] ?? 0.0)));
        $failureStreak        = max(0, (int) ($input['held_out_failure_streak']      ?? 0));
        $regressionSpike      = (bool) ($input['regression_spike']           ?? false);
        $hasMandatoryTelemetry= array_key_exists('has_mandatory_telemetry', $input)
            ? (bool) $input['has_mandatory_telemetry']
            : true;

        $killTriggers = [];
        if ($rollbackTelemetry) {
            $killTriggers[] = ['reason' => 'rollback_telemetry', 'recovery' => 'telemetry_confirms_stability', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($proxyLeakRate > self::PROXY_LEAK_CEILING) {
            $killTriggers[] = ['reason' => 'proxy_leak_ceiling_breach', 'recovery' => 'proxy_leak_rate_below_ceiling_for_2_cycles', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($failureStreak >= self::FAILURE_STREAK_LIMIT) {
            $killTriggers[] = ['reason' => 'held_out_failure_streak', 'recovery' => 'zero_consecutive_failures_for_5_tasks', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($regressionSpike) {
            $killTriggers[] = ['reason' => 'regression_spike', 'recovery' => 'regression_absent_for_10_tasks', 'policy' => 'disable_amplifier_immediately'];
        }
        if (! $hasMandatoryTelemetry) {
            $killTriggers[] = ['reason' => 'missing_mandatory_telemetry', 'recovery' => 'all_mandatory_telemetry_present', 'policy' => 'block_canary_until_telemetry_restored'];
        }

        $killSwitchActive  = $killTriggers !== [];
        $killReason        = $killSwitchActive ? $killTriggers[0]['reason']   : null;
        $recoveryCondition = $killSwitchActive ? $killTriggers[0]['recovery'] : 'no_recovery_needed';
        $safeModePolicy    = $killSwitchActive ? $killTriggers[0]['policy']   : 'normal_operation';

        // Ceiling breach checks (existing logic).
        $breached = [];
        if ($duplicateRate > self::DUPLICATE_CEILING) {
            $breached[] = sprintf('duplicate_rate:%.4f>%.2f', $duplicateRate, self::DUPLICATE_CEILING);
        }
        if ($giveBackRate > self::GIVE_BACK_CEILING) {
            $breached[] = sprintf('give_back_rate:%.4f>%.2f', $giveBackRate, self::GIVE_BACK_CEILING);
        }
        if ($malformedRate > self::MALFORMED_CEILING) {
            $breached[] = sprintf('malformed_rate:%.4f>%.2f', $malformedRate, self::MALFORMED_CEILING);
        }
        if ($weakEvidenceRate > self::WEAK_EVIDENCE_CEILING) {
            $breached[] = sprintf('weak_evidence_rate:%.4f>%.2f', $weakEvidenceRate, self::WEAK_EVIDENCE_CEILING);
        }
        if ($lowValueRate > self::LOW_VALUE_CEILING) {
            $breached[] = sprintf('low_value_rate:%.4f>%.2f', $lowValueRate, self::LOW_VALUE_CEILING);
        }

        // Kill switch overrides action to rollback immediately.
        if ($killSwitchActive || $breached !== []) {
            return [
                'schema'              => self::SCHEMA,
                'action'              => self::ACTION_ROLLBACK,
                'breached_thresholds' => $breached,
                'rollback_scope'      => 'canary_only',
                'sample_size'         => $sampleSize,
                'next_safe_variant'   => 'baseline',
                'kill_switch_active'  => $killSwitchActive,
                'kill_reason'         => $killReason,
                'recovery_condition'  => $recoveryCondition,
                'safe_mode_policy'    => $safeModePolicy,
            ];
        }

        if ($sampleSize < self::MIN_SAMPLE_SIZE) {
            return [
                'schema'              => self::SCHEMA,
                'action'              => self::ACTION_WAIT_FOR_SAMPLE,
                'breached_thresholds' => [],
                'rollback_scope'      => null,
                'sample_size'         => $sampleSize,
                'next_safe_variant'   => 'current_canary',
                'kill_switch_active'  => false,
                'kill_reason'         => null,
                'recovery_condition'  => 'no_recovery_needed',
                'safe_mode_policy'    => 'normal_operation',
            ];
        }

        return [
            'schema'              => self::SCHEMA,
            'action'              => self::ACTION_CONTINUE,
            'breached_thresholds' => [],
            'rollback_scope'      => null,
            'sample_size'         => $sampleSize,
            'next_safe_variant'   => 'current_canary',
            'kill_switch_active'  => false,
            'kill_reason'         => null,
            'recovery_condition'  => 'no_recovery_needed',
            'safe_mode_policy'    => 'normal_operation',
        ];
    }
}
