<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure canary kill-switch evaluator. Checks live canary metrics against
 * hard ceilings and recommends rollback, continue, or wait for more samples.
 *
 * AC2: rollback when any metric breaches its ceiling — including false_green_rate (structural
 *      gates passing while proof is fake/absent) and proxy_leak_rate (template/wrapper output).
 * AC3: continue only when all metrics are within thresholds AND sample size ≥ MIN.
 *      Insufficient sample with no breach → wait_for_sample. A failure streak fully explained
 *      by quota_exhausted is a temporary infra issue, not capability degradation — it returns
 *      pause_for_quota (safe fallback) instead of rollback; every other kill trigger still fires.
 * AC4: output always includes action, severity, breached_thresholds, rollback_scope,
 *      sample_size, next_safe_variant, kill_reason (trip reason), and recovery_condition
 *      (minimum evidence required to resume).
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAmplifierCanaryKillSwitch
{
    public const SCHEMA = 'atlas.external_brain.amplifier_canary_kill_switch.v1';

    public const ACTION_ROLLBACK          = 'rollback';
    public const ACTION_CONTINUE          = 'continue';
    public const ACTION_WAIT_FOR_SAMPLE   = 'wait_for_sample';
    public const ACTION_PAUSE_FOR_QUOTA   = 'pause_for_quota';

    public const SEVERITY_NONE     = 'none';
    public const SEVERITY_LOW      = 'low';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    private const MIN_SAMPLE_SIZE         = 10;

    // A variant recovering FROM a previous rollback must clear a larger sample
    // than a fresh canary — otherwise a tiny post-rollback sample could bounce
    // straight back to continue and oscillate with the next small regression.
    private const RECOVERY_MIN_SAMPLE_SIZE = 30;

    public const RECOVERY_WINDOW_NOT_APPLICABLE = 'not_applicable';
    public const RECOVERY_WINDOW_IN_PROGRESS    = 'in_recovery_window';
    public const RECOVERY_WINDOW_RECOVERED      = 'recovered';

    // Hard ceilings (inclusive breach at >)
    private const DUPLICATE_CEILING       = 0.20;
    private const GIVE_BACK_CEILING       = 0.25;
    private const MALFORMED_CEILING       = 0.10;
    private const WEAK_EVIDENCE_CEILING   = 0.30;
    private const LOW_VALUE_CEILING       = 0.30;

    // Kill-switch thresholds
    private const PROXY_LEAK_CEILING      = 0.15;
    private const FAILURE_STREAK_LIMIT    = 3;
    private const FALSE_GREEN_CEILING     = 0.15;
    private const POISON_RATE_CEILING     = 0.20;

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
     *   poison_rate?: float,
     *   evidence_quality?: float,
     *   route_id?: string,
     *   baseline_value?: float,
     *   canary_value?: float,
     *   safe_previous_route?: string,
     * }  $input
     * @return array{schema:string, action:string, breached_thresholds:list<string>, rollback_scope:string|null, sample_size:int, next_safe_variant:string, kill_switch_active:bool, kill_reason:string|null, recovery_condition:string, safe_mode_policy:string, route_id:string, failed_metric:string|null, baseline_value:float|null, canary_value:float|null, safe_previous_route:string}
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
        $wasPreviouslyRolledBack = (bool) ($input['was_previously_rolled_back'] ?? false);
        $falseGreenRate       = max(0.0, min(1.0, (float) ($input['false_green_rate'] ?? 0.0)));

        // New AC4 fields: poison rate, evidence quality, identity.
        $poisonRate           = max(0.0, min(1.0, (float) ($input['poison_rate'] ?? 0.0)));
        $evidenceQuality      = max(0.0, min(1.0, (float) ($input['evidence_quality'] ?? 1.0)));
        $routeId              = trim((string) ($input['route_id'] ?? ''));
        $baselineValue        = isset($input['baseline_value']) ? (float) $input['baseline_value'] : null;
        $canaryValue          = isset($input['canary_value']) ? (float) $input['canary_value'] : null;
        $safePreviousRoute    = trim((string) ($input['safe_previous_route'] ?? 'baseline'));

        // AC3: a failure streak explained by temporary quota exhaustion is NOT capability
        // degradation — rolling back the amplifier for it would be a false signal. Every other
        // kill trigger (telemetry, proxy leak, false-green, regression, missing telemetry) still
        // fires regardless, because those indicate the model/output itself, not infra availability.
        $quotaExhausted = (bool) ($input['quota_exhausted'] ?? false);
        $failureStreakExplainedByQuota = $quotaExhausted && $failureStreak >= self::FAILURE_STREAK_LIMIT;

        $killTriggers = [];
        if ($rollbackTelemetry) {
            $killTriggers[] = ['reason' => 'rollback_telemetry', 'recovery' => 'telemetry_confirms_stability', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($proxyLeakRate > self::PROXY_LEAK_CEILING) {
            $killTriggers[] = ['reason' => 'proxy_leak_ceiling_breach', 'recovery' => 'proxy_leak_rate_below_ceiling_for_2_cycles', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($falseGreenRate > self::FALSE_GREEN_CEILING) {
            $killTriggers[] = ['reason' => 'false_green_ceiling_breach', 'recovery' => 'false_green_rate_below_ceiling_for_2_cycles', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($failureStreak >= self::FAILURE_STREAK_LIMIT && ! $quotaExhausted) {
            $killTriggers[] = ['reason' => 'held_out_failure_streak', 'recovery' => 'zero_consecutive_failures_for_5_tasks', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($regressionSpike) {
            $killTriggers[] = ['reason' => 'regression_spike', 'recovery' => 'regression_absent_for_10_tasks', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($poisonRate > self::POISON_RATE_CEILING) {
            $killTriggers[] = ['reason' => 'poison_rate_ceiling_breach', 'recovery' => 'poison_rate_below_ceiling_for_2_cycles', 'policy' => 'disable_amplifier_immediately'];
        }
        if ($evidenceQuality < 0.5) {
            $killTriggers[] = ['reason' => 'evidence_quality_regression', 'recovery' => 'evidence_quality_above_0_5_for_10_samples', 'policy' => 'disable_amplifier_immediately'];
        }
        if (! $hasMandatoryTelemetry) {
            $killTriggers[] = ['reason' => 'missing_mandatory_telemetry', 'recovery' => 'all_mandatory_telemetry_present', 'policy' => 'block_canary_until_telemetry_restored'];
        }

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

        $killSwitchActive  = $killTriggers !== [];
        if ($killSwitchActive) {
            $killReason        = $killTriggers[0]['reason'];
            $recoveryCondition = $killTriggers[0]['recovery'];
            $safeModePolicy    = $killTriggers[0]['policy'];
        } elseif ($breached !== []) {
            // Rollback driven purely by ceiling breaches (no named kill-switch trigger) must
            // still report an actionable recovery state — never "no_recovery_needed" while
            // actively rolled back.
            $killReason        = 'ceiling_threshold_breach';
            $recoveryCondition = 'all_breached_thresholds_back_within_ceiling';
            $safeModePolicy    = 'disable_amplifier_until_thresholds_recover';
        } else {
            $killReason        = null;
            $recoveryCondition = 'no_recovery_needed';
            $safeModePolicy    = 'normal_operation';
        }

        // Kill switch overrides action to rollback immediately.
        if ($killSwitchActive || $breached !== []) {
            return $this->result(
                action: self::ACTION_ROLLBACK,
                severity: $killSwitchActive ? self::SEVERITY_CRITICAL : self::SEVERITY_HIGH,
                breached: $breached,
                rollbackScope: 'canary_only',
                sampleSize: $sampleSize,
                nextSafeVariant: 'baseline',
                killSwitchActive: $killSwitchActive,
                killReason: $killReason,
                recoveryCondition: $recoveryCondition,
                safeModePolicy: $safeModePolicy,
                recoveryWindow: self::RECOVERY_WINDOW_IN_PROGRESS,
                routeId: $routeId,
                failedMetric: $killReason,
                baselineValue: $baselineValue,
                canaryValue: $canaryValue,
                safePreviousRoute: $safePreviousRoute,
            );
        }

        // AC3: the failure streak looked like capability degradation but is fully explained by
        // temporary quota exhaustion — a safe fallback (pause, don't roll back) since the model
        // itself was never actually exercised enough to prove or disprove anything.
        if ($failureStreakExplainedByQuota) {
            return $this->result(
                action: self::ACTION_PAUSE_FOR_QUOTA,
                severity: self::SEVERITY_LOW,
                breached: [],
                rollbackScope: null,
                sampleSize: $sampleSize,
                nextSafeVariant: 'current_canary',
                killSwitchActive: false,
                killReason: 'temporary_quota_failure',
                recoveryCondition: 'quota_restored_and_failure_streak_clears',
                safeModePolicy: 'pause_sampling_until_quota_restored',
                recoveryWindow: self::RECOVERY_WINDOW_NOT_APPLICABLE,
                routeId: $routeId,
                failedMetric: null,
                baselineValue: $baselineValue,
                canaryValue: $canaryValue,
                safePreviousRoute: $safePreviousRoute,
            );
        }

        // Recovering FROM a previous rollback: normal-size samples are never enough on
        // their own — require the larger recovery sample before trusting a clean read.
        if ($wasPreviouslyRolledBack) {
            if ($sampleSize < self::RECOVERY_MIN_SAMPLE_SIZE) {
                return $this->result(
                    action: self::ACTION_WAIT_FOR_SAMPLE,
                    severity: self::SEVERITY_NONE,
                    breached: [],
                    rollbackScope: null,
                    sampleSize: $sampleSize,
                    nextSafeVariant: 'current_canary',
                    killSwitchActive: false,
                    killReason: null,
                    recoveryCondition: 'no_recovery_needed',
                    safeModePolicy: 'normal_operation',
                    recoveryWindow: self::RECOVERY_WINDOW_IN_PROGRESS,
                    routeId: $routeId,
                    failedMetric: null,
                    baselineValue: $baselineValue,
                    canaryValue: $canaryValue,
                    safePreviousRoute: $safePreviousRoute,
                );
            }

            return $this->result(
                action: self::ACTION_CONTINUE,
                severity: self::SEVERITY_NONE,
                breached: [],
                rollbackScope: null,
                sampleSize: $sampleSize,
                nextSafeVariant: 'current_canary',
                killSwitchActive: false,
                killReason: null,
                recoveryCondition: 'no_recovery_needed',
                safeModePolicy: 'normal_operation',
                recoveryWindow: self::RECOVERY_WINDOW_RECOVERED,
                routeId: $routeId,
                failedMetric: null,
                baselineValue: $baselineValue,
                canaryValue: $canaryValue,
                safePreviousRoute: $safePreviousRoute,
            );
        }

        if ($sampleSize < self::MIN_SAMPLE_SIZE) {
            return $this->result(
                action: self::ACTION_WAIT_FOR_SAMPLE,
                severity: self::SEVERITY_NONE,
                breached: [],
                rollbackScope: null,
                sampleSize: $sampleSize,
                nextSafeVariant: 'current_canary',
                killSwitchActive: false,
                killReason: null,
                recoveryCondition: 'no_recovery_needed',
                safeModePolicy: 'normal_operation',
                recoveryWindow: self::RECOVERY_WINDOW_NOT_APPLICABLE,
                routeId: $routeId,
                failedMetric: null,
                baselineValue: $baselineValue,
                canaryValue: $canaryValue,
                safePreviousRoute: $safePreviousRoute,
            );
        }

        return $this->result(
            action: self::ACTION_CONTINUE,
            severity: self::SEVERITY_NONE,
            breached: [],
            rollbackScope: null,
            sampleSize: $sampleSize,
            nextSafeVariant: 'current_canary',
            killSwitchActive: false,
            killReason: null,
            recoveryCondition: 'no_recovery_needed',
            safeModePolicy: 'normal_operation',
            recoveryWindow: self::RECOVERY_WINDOW_NOT_APPLICABLE,
            routeId: $routeId,
            failedMetric: null,
            baselineValue: $baselineValue,
            canaryValue: $canaryValue,
            safePreviousRoute: $safePreviousRoute,
        );
    }

    /**
     * @param  list<string>  $breached
     * @return array<string,mixed>
     */
    private function result(
        string $action,
        string $severity,
        array $breached,
        ?string $rollbackScope,
        int $sampleSize,
        string $nextSafeVariant,
        bool $killSwitchActive,
        ?string $killReason,
        string $recoveryCondition,
        string $safeModePolicy,
        string $recoveryWindow,
        string $routeId,
        ?string $failedMetric,
        ?float $baselineValue,
        ?float $canaryValue,
        string $safePreviousRoute,
    ): array {
        return [
            'schema'                 => self::SCHEMA,
            'action'                 => $action,
            'severity'               => $severity,
            'breached_thresholds'    => $breached,
            'rollback_scope'         => $rollbackScope,
            'sample_size'            => $sampleSize,
            'next_safe_variant'      => $nextSafeVariant,
            'kill_switch_active'     => $killSwitchActive,
            'kill_reason'            => $killReason,
            'recovery_condition'     => $recoveryCondition,
            'safe_mode_policy'       => $safeModePolicy,
            'recovery_window_status' => $recoveryWindow,
            'route_id'               => $routeId,
            'failed_metric'          => $failedMetric,
            'baseline_value'         => $baselineValue,
            'canary_value'           => $canaryValue,
            'safe_previous_route'    => $safePreviousRoute,
        ];
    }
}