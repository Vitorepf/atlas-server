<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure control-plane selector. Collapses model-amplifier signals into one
 * operator-free origination mode decision.
 *
 * Mode resolution — first match wins (fail-closed):
 *   rollback       — kill_switch_active OR telemetry='rollback_candidate' OR blocking_signals
 *   canary         — canary_enabled AND healthy AND promotion_candidate AND slo_met
 *   shadow_only    — shadow_enabled AND NOT promotion_candidate
 *   scaffolded_live — scaffolded_available AND slo_met (promoted but not yet at canary threshold)
 *   baseline       — default / fail-closed fallback
 *
 * AC3: fails closed (→ baseline) when required signals (telemetry_status) are absent
 *      or when contradictory signals prevent safe resolution.
 *
 * AC4: output always includes mode, reasons, blocking_signals, next_safe_action,
 *      provider_independence_status.
 *
 * Pure: no I/O, no provider calls, no side effects.
 */
final class AtlasExternalBrainAmplifierControlPlane
{
    public const SCHEMA = 'atlas.external_brain.amplifier_control_plane.v1';

    public const MODE_ROLLBACK            = 'rollback';
    public const MODE_CANARY              = 'canary';
    public const MODE_SHADOW_ONLY         = 'shadow_only';
    public const MODE_SCAFFOLDED_LIVE     = 'scaffolded_live';
    public const MODE_FRONTIER_ESCALATION = 'frontier_escalation';
    public const MODE_BASELINE            = 'baseline';

    public const TELEMETRY_HEALTHY  = 'healthy';
    public const TELEMETRY_WATCH    = 'watch';
    public const TELEMETRY_ROLLBACK = 'rollback_candidate';

    private const HELD_OUT_PASS_THRESHOLD = 0.70;
    private const PROXY_LEAKAGE_THRESHOLD = 0.20;
    private const HIGH_LEVERAGE_THRESHOLD = 7.0;

    /**
     * @param  array{
     *   kill_switch_active?: bool,
     *   telemetry_status?: string,
     *   blocking_signals?: list<string>,
     *   promotion_candidate?: bool,
     *   slo_met?: bool,
     *   shadow_enabled?: bool,
     *   canary_enabled?: bool,
     *   scaffolded_available?: bool,
     * }  $input
     * @return array{schema:string, mode:string, reasons:list<string>, blocking_signals:list<string>, next_safe_action:string, provider_independence_status:string}
     */
    public function decide(array $input): array
    {
        // Fail-closed when telemetry_status is absent (required signal)
        if (! array_key_exists('telemetry_status', $input)) {
            return $this->result(
                self::MODE_BASELINE,
                ['required_telemetry_missing:fail_closed_to_baseline'],
                [],
                'await_telemetry_signal_before_promoting',
            );
        }

        $killSwitch       = (bool) ($input['kill_switch_active']    ?? false);
        $telemetry        = (string) ($input['telemetry_status']    ?? self::TELEMETRY_WATCH);
        $blockingSignals  = (array)  ($input['blocking_signals']    ?? []);
        $promotionReady   = (bool) ($input['promotion_candidate']   ?? false);
        $sloMet           = (bool) ($input['slo_met']               ?? false);
        $shadowEnabled    = (bool) ($input['shadow_enabled']        ?? false);
        $canaryEnabled    = (bool) ($input['canary_enabled']        ?? false);
        $scaffoldAvail    = (bool) ($input['scaffolded_available']  ?? false);

        // Contradictory: healthy telemetry but non-empty blocking signals
        if ($telemetry === self::TELEMETRY_HEALTHY && $blockingSignals !== []) {
            return $this->result(
                self::MODE_BASELINE,
                ['contradictory_signals:healthy_telemetry_with_blocking_signals:fail_closed'],
                $blockingSignals,
                'investigate_signal_contradiction_before_promoting',
            );
        }

        // ROLLBACK
        if ($killSwitch || $telemetry === self::TELEMETRY_ROLLBACK || $blockingSignals !== []) {
            $reasons = [];
            if ($killSwitch) {
                $reasons[] = 'kill_switch_active';
            }
            if ($telemetry === self::TELEMETRY_ROLLBACK) {
                $reasons[] = 'telemetry_status:rollback_candidate';
            }
            if ($blockingSignals !== []) {
                $reasons[] = 'blocking_signals_present';
            }
            return $this->result(
                self::MODE_ROLLBACK,
                $reasons,
                $blockingSignals,
                'disable_amplifier_and_restore_baseline_origination',
            );
        }

        // Quality evidence (held_out_pass_rate / proxy_leakage_rate) gates EVERY promotion mode
        // below it, not just frontier escalation — a closed-loop decision must never promote to
        // canary or scaffolded_live on stale/false promotion_candidate/slo_met flags when the
        // actual replay/leakage evidence disqualifies it.
        $heldOutPassRate    = isset($input['held_out_pass_rate'])       ? (float) $input['held_out_pass_rate']       : null;
        $proxyLeakageRate   = isset($input['proxy_leakage_rate'])       ? (float) $input['proxy_leakage_rate']       : null;
        $structuralLeverage = isset($input['structural_leverage_score']) ? (float) $input['structural_leverage_score'] : null;

        $qualityFails = ($heldOutPassRate !== null && $heldOutPassRate < self::HELD_OUT_PASS_THRESHOLD)
                     || ($proxyLeakageRate !== null && $proxyLeakageRate > self::PROXY_LEAKAGE_THRESHOLD);
        $highLeverage = $structuralLeverage !== null && $structuralLeverage >= self::HIGH_LEVERAGE_THRESHOLD;

        // CANARY
        if ($canaryEnabled && $telemetry === self::TELEMETRY_HEALTHY && $promotionReady && $sloMet && ! $qualityFails) {
            return $this->result(
                self::MODE_CANARY,
                ['canary_enabled', 'telemetry_healthy', 'promotion_candidate', 'slo_met'],
                [],
                'monitor_canary_pass_rate_for_full_promotion',
            );
        }

        // SHADOW_ONLY
        if ($shadowEnabled && ! $promotionReady) {
            return $this->result(
                self::MODE_SHADOW_ONLY,
                ['shadow_enabled', 'promotion_not_yet_met'],
                [],
                'continue_shadow_runs_until_promotion_candidate',
            );
        }

        // SCAFFOLDED_LIVE
        if ($scaffoldAvail && $sloMet && ! $qualityFails) {
            return $this->result(
                self::MODE_SCAFFOLDED_LIVE,
                ['scaffolded_available', 'slo_met'],
                [],
                'monitor_slo_and_engage_canary_when_ready',
            );
        }

        // FRONTIER_ESCALATION — only when quality signals are explicitly supplied

        if ($qualityFails && $highLeverage) {
            $reasons = [];
            if ($heldOutPassRate !== null && $heldOutPassRate < self::HELD_OUT_PASS_THRESHOLD) {
                $reasons[] = 'held_out_pass_rate_below_threshold';
            }
            if ($proxyLeakageRate !== null && $proxyLeakageRate > self::PROXY_LEAKAGE_THRESHOLD) {
                $reasons[] = 'proxy_leakage_exceeds_threshold';
            }
            $reasons[] = 'structural_leverage_high';
            return $this->result(
                self::MODE_FRONTIER_ESCALATION,
                $reasons,
                [],
                'escalate_to_frontier_model_for_high_leverage_task',
            );
        }

        // BASELINE — safe default
        return $this->result(
            self::MODE_BASELINE,
            ['no_amplifier_condition_met:defaulting_to_baseline'],
            [],
            'configure_amplifier_conditions_to_unlock_higher_modes',
        );
    }

    /** @param list<string> $reasons @param list<string> $blocking */
    private function result(string $mode, array $reasons, array $blocking, string $nextAction): array
    {
        return [
            'schema'                      => self::SCHEMA,
            'mode'                        => $mode,
            'reasons'                     => $reasons,
            'blocking_signals'            => $blocking,
            'next_safe_action'            => $nextAction,
            'provider_independence_status' => 'provider_free:pure_signal_evaluation',
        ];
    }
}
