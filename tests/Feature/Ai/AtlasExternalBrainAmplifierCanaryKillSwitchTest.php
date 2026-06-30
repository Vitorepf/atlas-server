<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierCanaryKillSwitch;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierCanaryKillSwitchTest extends TestCase
{
    private function ks(): AtlasExternalBrainAmplifierCanaryKillSwitch
    {
        return new AtlasExternalBrainAmplifierCanaryKillSwitch;
    }

    private function cleanMetrics(int $sampleSize = 20): array
    {
        return [
            'sample_size'            => $sampleSize,
            'duplicate_rate'         => 0.05,
            'give_back_rate'         => 0.05,
            'malformed_rate'         => 0.02,
            'weak_evidence_rate'     => 0.10,
            'low_value_rate'         => 0.10,
            'proxy_leak_rate'        => 0.05,
            'held_out_failure_streak'=> 0,
            'regression_spike'       => false,
            'rollback_telemetry'     => false,
            'has_mandatory_telemetry'=> true,
        ];
    }

    // ── AC2: kill-switch triggers → rollback + kill_switch_active ────────────

    public function test_proxy_leak_above_ceiling_triggers_rollback(): void
    {
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(), [
            'proxy_leak_rate' => 0.20,
        ]));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $r['action']);
        $this->assertTrue($r['kill_switch_active']);
        $this->assertSame('proxy_leak_ceiling_breach', $r['kill_reason']);
    }

    public function test_held_out_failure_streak_at_limit_triggers_rollback(): void
    {
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(), [
            'held_out_failure_streak' => 3,
        ]));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $r['action']);
        $this->assertTrue($r['kill_switch_active']);
        $this->assertSame('held_out_failure_streak', $r['kill_reason']);
    }

    public function test_regression_spike_triggers_rollback(): void
    {
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(), [
            'regression_spike' => true,
        ]));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $r['action']);
        $this->assertTrue($r['kill_switch_active']);
        $this->assertSame('regression_spike', $r['kill_reason']);
    }

    public function test_rollback_telemetry_triggers_rollback(): void
    {
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(), [
            'rollback_telemetry' => true,
        ]));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $r['action']);
        $this->assertTrue($r['kill_switch_active']);
        $this->assertSame('rollback_telemetry', $r['kill_reason']);
    }

    public function test_missing_mandatory_telemetry_triggers_rollback(): void
    {
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(), [
            'has_mandatory_telemetry' => false,
        ]));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $r['action']);
        $this->assertTrue($r['kill_switch_active']);
        $this->assertSame('missing_mandatory_telemetry', $r['kill_reason']);
    }

    public function test_ceiling_breach_triggers_rollback_without_kill_switch(): void
    {
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(), [
            'duplicate_rate' => 0.30,
        ]));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $r['action']);
        $this->assertFalse($r['kill_switch_active']);
        $this->assertNotEmpty($r['breached_thresholds']);
    }

    // ── AC3: insufficient sample + no breach → wait_for_sample ───────────────

    public function test_insufficient_sample_no_breach_returns_wait(): void
    {
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(5), []));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_WAIT_FOR_SAMPLE, $r['action']);
        $this->assertFalse($r['kill_switch_active']);
        $this->assertSame([], $r['breached_thresholds']);
    }

    public function test_kill_switch_trigger_with_small_sample_still_rolls_back(): void
    {
        // Kill switch overrides wait_for_sample
        $r = $this->ks()->evaluate(array_merge($this->cleanMetrics(3), [
            'regression_spike' => true,
        ]));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_ROLLBACK, $r['action']);
        $this->assertTrue($r['kill_switch_active']);
    }

    // ── AC4: clean metrics + enough sample → continue ─────────────────────────

    public function test_clean_metrics_with_enough_sample_returns_continue(): void
    {
        $r = $this->ks()->evaluate($this->cleanMetrics(20));

        $this->assertSame(AtlasExternalBrainAmplifierCanaryKillSwitch::ACTION_CONTINUE, $r['action']);
        $this->assertFalse($r['kill_switch_active']);
        $this->assertSame([], $r['breached_thresholds']);
        $this->assertNotEmpty($r['next_safe_variant']);
    }

    public function test_continue_has_no_rollback_scope(): void
    {
        $r = $this->ks()->evaluate($this->cleanMetrics(20));

        $this->assertNull($r['rollback_scope']);
    }

    // ── Required output keys always present ───────────────────────────────────

    public function test_output_always_has_required_keys(): void
    {
        foreach ([
            $this->cleanMetrics(20),
            $this->cleanMetrics(3),
            array_merge($this->cleanMetrics(), ['regression_spike' => true]),
        ] as $input) {
            $r = $this->ks()->evaluate($input);
            foreach (['action', 'breached_thresholds', 'rollback_scope', 'sample_size', 'next_safe_variant'] as $key) {
                $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
            }
        }
    }

    public function test_evaluate_is_deterministic(): void
    {
        $input = $this->cleanMetrics(20);
        $a = $this->ks()->evaluate($input);
        $b = $this->ks()->evaluate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
