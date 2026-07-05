<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierCanaryKillSwitch;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierCanaryKillSwitchTest extends TestCase
{
    private AtlasExternalBrainAmplifierCanaryKillSwitch $switch;

    protected function setUp(): void
    {
        $this->switch = new AtlasExternalBrainAmplifierCanaryKillSwitch;
    }

    private function allGood(int $sampleSize = 20): array
    {
        return [
            'sample_size'        => $sampleSize,
            'duplicate_rate'     => 0.05,
            'give_back_rate'     => 0.10,
            'malformed_rate'     => 0.02,
            'weak_evidence_rate' => 0.10,
            'low_value_rate'     => 0.10,
        ];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->switch->evaluate($this->allGood());

        foreach ([
            'schema', 'action', 'breached_thresholds', 'rollback_scope',
            'sample_size', 'next_safe_variant',
            'kill_switch_active', 'kill_reason', 'recovery_condition', 'safe_mode_policy',
            'recovery_window_status', 'route_id', 'failed_metric',
            'baseline_value', 'canary_value', 'safe_previous_route',
        ] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::SCHEMA, $result['schema']);
    }

    // ── AC3: continue only when metrics OK + sufficient samples ──────────────

    public function test_all_metrics_ok_with_enough_samples_continues(): void
    {
        $result = $this->switch->evaluate($this->allGood(20));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_CONTINUE, $result['action']);
        $this->assertSame([], $result['breached_thresholds']);
        $this->assertNull($result['rollback_scope']);
        $this->assertSame('current_canary', $result['next_safe_variant']);
    }

    public function test_metrics_ok_but_insufficient_samples_waits(): void
    {
        $result = $this->switch->evaluate($this->allGood(5)); // < MIN_SAMPLE_SIZE (10)

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_WAIT_FOR_SAMPLE, $result['action']);
        $this->assertSame([], $result['breached_thresholds']);
    }

    // ── AC2: rollback on duplicate breach ────────────────────────────────────

    public function test_duplicate_rate_above_ceiling_triggers_rollback(): void
    {
        $input                   = $this->allGood();
        $input['duplicate_rate'] = 0.25; // > 0.20

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertStringContainsString('duplicate_rate', implode(' ', $result['breached_thresholds']));
    }

    // ── AC2: rollback on give_back breach ─────────────────────────────────────

    public function test_give_back_rate_above_ceiling_triggers_rollback(): void
    {
        $input                  = $this->allGood();
        $input['give_back_rate'] = 0.30; // > 0.25

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertStringContainsString('give_back_rate', implode(' ', $result['breached_thresholds']));
    }

    // ── AC2: rollback on malformed breach ─────────────────────────────────────

    public function test_malformed_rate_above_ceiling_triggers_rollback(): void
    {
        $input                  = $this->allGood();
        $input['malformed_rate'] = 0.15; // > 0.10

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertStringContainsString('malformed_rate', implode(' ', $result['breached_thresholds']));
    }

    // ── AC2: rollback on weak-evidence breach ─────────────────────────────────

    public function test_weak_evidence_rate_above_ceiling_triggers_rollback(): void
    {
        $input                       = $this->allGood();
        $input['weak_evidence_rate'] = 0.35; // > 0.30

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertStringContainsString('weak_evidence_rate', implode(' ', $result['breached_thresholds']));
    }

    // ── AC2: rollback on low-value flooding ───────────────────────────────────

    public function test_low_value_rate_above_ceiling_triggers_rollback(): void
    {
        $input                 = $this->allGood();
        $input['low_value_rate'] = 0.40; // > 0.30

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertStringContainsString('low_value_rate', implode(' ', $result['breached_thresholds']));
    }

    public function test_ceiling_breach_alone_reports_actionable_recovery_not_no_recovery_needed(): void
    {
        $input                   = $this->allGood();
        $input['duplicate_rate'] = 0.50; // breach, no kill-switch trigger involved

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertFalse($result['kill_switch_active']);
        $this->assertNotSame('no_recovery_needed', $result['recovery_condition']);
        $this->assertNotSame('normal_operation', $result['safe_mode_policy']);
        $this->assertNotNull($result['kill_reason']);
    }

    // ── Multiple breaches all appear in breached_thresholds ──────────────────

    public function test_multiple_breaches_all_reported(): void
    {
        $result = $this->switch->evaluate([
            'sample_size'        => 50,
            'duplicate_rate'     => 0.50, // breach
            'give_back_rate'     => 0.50, // breach
            'malformed_rate'     => 0.02,
            'weak_evidence_rate' => 0.05,
            'low_value_rate'     => 0.05,
        ]);

        $this->assertCount(2, $result['breached_thresholds']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
    }

    // ── Rollback output fields ─────────────────────────────────────────────────

    public function test_rollback_scope_is_canary_only(): void
    {
        $input                   = $this->allGood();
        $input['duplicate_rate'] = 0.99;

        $result = $this->switch->evaluate($input);

        $this->assertSame('canary_only', $result['rollback_scope']);
    }

    public function test_rollback_recommends_baseline_as_next_safe_variant(): void
    {
        $input                   = $this->allGood();
        $input['duplicate_rate'] = 0.99;

        $result = $this->switch->evaluate($input);

        $this->assertSame('baseline', $result['next_safe_variant']);
    }

    // ── Breach overrides insufficient sample ──────────────────────────────────

    public function test_breach_with_low_sample_still_rolls_back(): void
    {
        $result = $this->switch->evaluate([
            'sample_size'    => 2, // below minimum
            'duplicate_rate' => 0.99, // clear breach
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
    }

    // ── sample_size echoed in output ──────────────────────────────────────────

    public function test_sample_size_is_echoed_in_output(): void
    {
        $result = $this->switch->evaluate($this->allGood(42));

        $this->assertSame(42, $result['sample_size']);
    }

    // ── Kill switch: safe state ───────────────────────────────────────────────

    public function test_kill_switch_inactive_when_all_safe(): void
    {
        $result = $this->switch->evaluate($this->allGood(20));

        $this->assertFalse($result['kill_switch_active']);
        $this->assertNull($result['kill_reason']);
        $this->assertSame('normal_operation', $result['safe_mode_policy']);
        $this->assertSame('no_recovery_needed', $result['recovery_condition']);
    }

    // ── Kill switch: rollback telemetry ───────────────────────────────────────

    public function test_rollback_telemetry_triggers_kill_switch(): void
    {
        $input = array_merge($this->allGood(20), ['rollback_telemetry' => true]);
        $result = $this->switch->evaluate($input);

        $this->assertTrue($result['kill_switch_active']);
        $this->assertSame('rollback_telemetry', $result['kill_reason']);
        $this->assertSame('disable_amplifier_immediately', $result['safe_mode_policy']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
    }

    // ── Kill switch: proxy leak ceiling breach ────────────────────────────────

    public function test_proxy_leak_rate_above_ceiling_triggers_kill_switch(): void
    {
        $input = array_merge($this->allGood(20), ['proxy_leak_rate' => 0.20]); // > 0.15
        $result = $this->switch->evaluate($input);

        $this->assertTrue($result['kill_switch_active']);
        $this->assertSame('proxy_leak_ceiling_breach', $result['kill_reason']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
    }

    public function test_proxy_leak_rate_at_ceiling_does_not_trigger_kill_switch(): void
    {
        $input = array_merge($this->allGood(20), ['proxy_leak_rate' => 0.15]); // exactly at ceiling
        $result = $this->switch->evaluate($input);

        $this->assertFalse($result['kill_switch_active']);
    }

    // ── Kill switch: held-out failure streak ──────────────────────────────────

    public function test_failure_streak_at_limit_triggers_kill_switch(): void
    {
        $input = array_merge($this->allGood(20), ['held_out_failure_streak' => 3]);
        $result = $this->switch->evaluate($input);

        $this->assertTrue($result['kill_switch_active']);
        $this->assertSame('held_out_failure_streak', $result['kill_reason']);
        $this->assertStringContainsString('zero_consecutive_failures', $result['recovery_condition']);
    }

    public function test_failure_streak_below_limit_does_not_trigger(): void
    {
        $input = array_merge($this->allGood(20), ['held_out_failure_streak' => 2]);
        $result = $this->switch->evaluate($input);

        $this->assertFalse($result['kill_switch_active']);
    }

    // ── Kill switch: regression spike ─────────────────────────────────────────

    public function test_regression_spike_triggers_kill_switch(): void
    {
        $input = array_merge($this->allGood(20), ['regression_spike' => true]);
        $result = $this->switch->evaluate($input);

        $this->assertTrue($result['kill_switch_active']);
        $this->assertSame('regression_spike', $result['kill_reason']);
        $this->assertStringContainsString('regression_absent', $result['recovery_condition']);
    }

    // ── Kill switch: missing mandatory telemetry ──────────────────────────────

    public function test_missing_mandatory_telemetry_triggers_kill_switch(): void
    {
        $input = array_merge($this->allGood(20), ['has_mandatory_telemetry' => false]);
        $result = $this->switch->evaluate($input);

        $this->assertTrue($result['kill_switch_active']);
        $this->assertSame('missing_mandatory_telemetry', $result['kill_reason']);
        $this->assertSame('block_canary_until_telemetry_restored', $result['safe_mode_policy']);
        $this->assertSame('all_mandatory_telemetry_present', $result['recovery_condition']);
    }

    public function test_has_mandatory_telemetry_true_does_not_trigger(): void
    {
        $input = array_merge($this->allGood(20), ['has_mandatory_telemetry' => true]);
        $result = $this->switch->evaluate($input);

        $this->assertFalse($result['kill_switch_active']);
    }

    // ── Kill switch: determinism ──────────────────────────────────────────────

    public function test_kill_reason_is_deterministic_for_same_input(): void
    {
        $input = array_merge($this->allGood(20), ['rollback_telemetry' => true, 'regression_spike' => true]);

        $this->assertSame(
            $this->switch->evaluate($input)['kill_reason'],
            $this->switch->evaluate($input)['kill_reason'],
        );
    }

    public function test_first_triggered_reason_wins_when_multiple_kill_switches_fire(): void
    {
        // rollback_telemetry fires first (priority order)
        $input = array_merge($this->allGood(20), [
            'rollback_telemetry' => true,
            'regression_spike'   => true,
        ]);
        $result = $this->switch->evaluate($input);

        $this->assertSame('rollback_telemetry', $result['kill_reason']);
    }

    // ── Recovery window ─────────────────────────────────────────────────────

    public function test_fresh_canary_has_recovery_window_not_applicable(): void
    {
        $result = $this->switch->evaluate($this->allGood(20));

        $this->assertSame('not_applicable', $result['recovery_window_status']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_CONTINUE, $result['action']);
    }

    public function test_previous_rollback_with_normal_sample_still_waits_for_recovery_sample(): void
    {
        $input = array_merge($this->allGood(20), ['was_previously_rolled_back' => true]);
        $result = $this->switch->evaluate($input);

        // 20 clears MIN_SAMPLE_SIZE (10) but not RECOVERY_MIN_SAMPLE_SIZE (30)
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_WAIT_FOR_SAMPLE, $result['action']);
        $this->assertSame('in_recovery_window', $result['recovery_window_status']);
    }

    public function test_previous_rollback_with_sufficient_recovery_sample_and_clean_metrics_continues(): void
    {
        $input = array_merge($this->allGood(30), ['was_previously_rolled_back' => true]);
        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_CONTINUE, $result['action']);
        $this->assertSame('recovered', $result['recovery_window_status']);
    }

    public function test_previous_rollback_still_rolls_back_on_breach_regardless_of_sample_size(): void
    {
        $input = array_merge($this->allGood(50), [
            'was_previously_rolled_back' => true,
            'duplicate_rate'              => 0.99,
        ]);
        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertSame('in_recovery_window', $result['recovery_window_status']);
    }

    public function test_missing_mandatory_telemetry_blocks_recovery_even_with_green_metrics(): void
    {
        $input = array_merge($this->allGood(50), [
            'was_previously_rolled_back' => true,
            'has_mandatory_telemetry'    => false,
        ]);
        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertSame('missing_mandatory_telemetry', $result['kill_reason']);
    }

    public function test_rollback_result_includes_recovery_window_status(): void
    {
        $input = array_merge($this->allGood(), ['duplicate_rate' => 0.99]);
        $result = $this->switch->evaluate($input);

        $this->assertArrayHasKey('recovery_window_status', $result);
        $this->assertSame('in_recovery_window', $result['recovery_window_status']);
    }

    public function test_wait_for_sample_result_includes_recovery_window_status(): void
    {
        $result = $this->switch->evaluate($this->allGood(5));

        $this->assertArrayHasKey('recovery_window_status', $result);
        $this->assertSame('not_applicable', $result['recovery_window_status']);
    }

    // ── AC4: severity present on every action ─────────────────────────────────

    public function test_continue_has_none_severity(): void
    {
        $result = $this->switch->evaluate($this->allGood(20));

        $this->assertArrayHasKey('severity', $result);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::SEVERITY_NONE, $result['severity']);
    }

    public function test_wait_for_sample_has_none_severity(): void
    {
        $result = $this->switch->evaluate($this->allGood(5));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::SEVERITY_NONE, $result['severity']);
    }

    public function test_ceiling_breach_without_kill_switch_has_high_severity(): void
    {
        $input = $this->allGood();
        $input['duplicate_rate'] = 0.50;

        $result = $this->switch->evaluate($input);

        $this->assertFalse($result['kill_switch_active']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::SEVERITY_HIGH, $result['severity']);
    }

    public function test_kill_switch_trigger_has_critical_severity(): void
    {
        $input = array_merge($this->allGood(20), ['rollback_telemetry' => true]);

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::SEVERITY_CRITICAL, $result['severity']);
    }

    // ── AC2: false-green evidence (structural gates pass, proof is fake/absent) ────

    public function test_false_green_rate_above_ceiling_triggers_kill_switch(): void
    {
        $input = array_merge($this->allGood(20), ['false_green_rate' => 0.20]); // > 0.15

        $result = $this->switch->evaluate($input);

        $this->assertTrue($result['kill_switch_active']);
        $this->assertSame('false_green_ceiling_breach', $result['kill_reason']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::SEVERITY_CRITICAL, $result['severity']);
    }

    public function test_false_green_rate_at_ceiling_does_not_trigger(): void
    {
        $input = array_merge($this->allGood(20), ['false_green_rate' => 0.15]);

        $result = $this->switch->evaluate($input);

        $this->assertFalse($result['kill_switch_active']);
    }

    // ── AC3: temporary quota failure vs capability degradation ────────────────

    public function test_failure_streak_explained_by_quota_pauses_instead_of_rolling_back(): void
    {
        $input = array_merge($this->allGood(20), [
            'held_out_failure_streak' => 3,
            'quota_exhausted' => true,
        ]);

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_PAUSE_FOR_QUOTA, $result['action']);
        $this->assertFalse($result['kill_switch_active']);
        $this->assertNull($result['rollback_scope']);
        $this->assertSame('temporary_quota_failure', $result['kill_reason']);
        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::SEVERITY_LOW, $result['severity']);
    }

    public function test_failure_streak_without_quota_flag_still_rolls_back(): void
    {
        $input = array_merge($this->allGood(20), ['held_out_failure_streak' => 3]);

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertTrue($result['kill_switch_active']);
    }

    public function test_quota_exhausted_alone_without_failure_streak_does_not_pause(): void
    {
        $input = array_merge($this->allGood(20), ['quota_exhausted' => true]);

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_CONTINUE, $result['action']);
    }

    public function test_quota_exhausted_does_not_suppress_other_kill_triggers(): void
    {
        $input = array_merge($this->allGood(20), [
            'held_out_failure_streak' => 3,
            'quota_exhausted' => true,
            'rollback_telemetry' => true,
        ]);

        $result = $this->switch->evaluate($input);

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $result['action']);
        $this->assertSame('rollback_telemetry', $result['kill_reason']);
    }
}
