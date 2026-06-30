<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierPromotionGate;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierPromotionGateTest extends TestCase
{
    private function svc(): AtlasExternalBrainAmplifierPromotionGate
    {
        return new AtlasExternalBrainAmplifierPromotionGate;
    }

    /** Passing input — all conditions satisfied. */
    private function passing(): array
    {
        return [
            'shadow_runs' => 30,
            'sustained_lift_ratio' => 0.15,
            'slo_passed' => true,
            'replay_court_passed' => true,
            'scaffold_compliance' => true,
            'overfit_detected' => false,
            'give_back_delta' => 0.00,
            'poison_delta' => 0.00,
        ];
    }

    private function evaluate(array $overrides = []): array
    {
        return $this->svc()->evaluate(array_merge($this->passing(), $overrides));
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_all_conditions_met_allows_promotion(): void
    {
        $r = $this->evaluate();

        $this->assertTrue($r['promote']);
        $this->assertSame([], $r['blocking_reasons']);
    }

    public function test_promotion_emits_live_rollout_constraints(): void
    {
        $r = $this->evaluate();

        $this->assertNotEmpty($r['live_rollout_constraints']);
        $this->assertContains('canary_first', $r['live_rollout_constraints']);
    }

    // ── sample size ───────────────────────────────────────────────────────────

    public function test_too_few_shadow_runs_blocks(): void
    {
        $r = $this->evaluate(['shadow_runs' => 15]);

        $this->assertFalse($r['promote']);
        $this->assertContains('sample_too_small', $r['blocking_reasons']);
        $this->assertSame(15, $r['required_more_shadow_runs']);
    }

    public function test_exact_min_shadow_runs_not_blocked_for_sample(): void
    {
        $r = $this->evaluate(['shadow_runs' => 30]);

        $this->assertNotContains('sample_too_small', $r['blocking_reasons']);
        $this->assertSame(0, $r['required_more_shadow_runs']);
    }

    // ── sustained lift ────────────────────────────────────────────────────────

    public function test_lift_below_minimum_blocks(): void
    {
        $r = $this->evaluate(['sustained_lift_ratio' => 0.05]);

        $this->assertFalse($r['promote']);
        $this->assertContains('lift_not_sustained', $r['blocking_reasons']);
    }

    public function test_lift_at_minimum_passes(): void
    {
        $r = $this->evaluate(['sustained_lift_ratio' => 0.10]);

        $this->assertNotContains('lift_not_sustained', $r['blocking_reasons']);
    }

    // ── overfit ───────────────────────────────────────────────────────────────

    public function test_overfit_detected_blocks(): void
    {
        $r = $this->evaluate(['overfit_detected' => true]);

        $this->assertFalse($r['promote']);
        $this->assertContains('overfit_detected', $r['blocking_reasons']);
    }

    // ── give_back and poison ──────────────────────────────────────────────────

    public function test_give_back_delta_above_max_blocks(): void
    {
        $r = $this->evaluate(['give_back_delta' => 0.06]);

        $this->assertFalse($r['promote']);
        $this->assertContains('give_back_risk_increased', $r['blocking_reasons']);
    }

    public function test_give_back_delta_at_max_passes(): void
    {
        $r = $this->evaluate(['give_back_delta' => 0.05]);

        $this->assertNotContains('give_back_risk_increased', $r['blocking_reasons']);
    }

    public function test_any_poison_increase_blocks(): void
    {
        $r = $this->evaluate(['poison_delta' => 0.01]);

        $this->assertFalse($r['promote']);
        $this->assertContains('poison_risk_increased', $r['blocking_reasons']);
    }

    public function test_negative_poison_delta_is_fine(): void
    {
        $r = $this->evaluate(['poison_delta' => -0.05]);

        $this->assertNotContains('poison_risk_increased', $r['blocking_reasons']);
    }

    // ── required evidence ─────────────────────────────────────────────────────

    public function test_slo_not_passed_blocks(): void
    {
        $r = $this->evaluate(['slo_passed' => false]);

        $this->assertFalse($r['promote']);
        $this->assertContains('slo_not_passed', $r['blocking_reasons']);
    }

    public function test_replay_court_not_passed_blocks(): void
    {
        $r = $this->evaluate(['replay_court_passed' => false]);

        $this->assertFalse($r['promote']);
        $this->assertContains('replay_court_not_passed', $r['blocking_reasons']);
    }

    public function test_scaffold_compliance_missing_blocks(): void
    {
        $r = $this->evaluate(['scaffold_compliance' => false]);

        $this->assertFalse($r['promote']);
        $this->assertContains('scaffold_compliance_missing', $r['blocking_reasons']);
    }

    // ── no rollout constraints when blocked ───────────────────────────────────

    public function test_blocked_gate_emits_no_live_rollout_constraints(): void
    {
        $r = $this->evaluate(['slo_passed' => false]);

        $this->assertSame([], $r['live_rollout_constraints']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->evaluate([]);

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::SCHEMA, $r['schema_version']);
    }
}
