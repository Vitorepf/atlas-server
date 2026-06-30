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

        foreach (['schema', 'action', 'breached_thresholds', 'rollback_scope', 'sample_size', 'next_safe_variant'] as $k) {
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
}
