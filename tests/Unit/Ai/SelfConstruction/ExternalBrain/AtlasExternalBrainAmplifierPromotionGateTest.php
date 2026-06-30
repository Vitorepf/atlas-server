<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierPromotionGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierPromotionGateTest extends TestCase
{
    private function svc(): AtlasExternalBrainAmplifierPromotionGate
    {
        return new AtlasExternalBrainAmplifierPromotionGate;
    }

    /** Passing input — all conditions satisfied (legacy + new gates). */
    private function passing(): array
    {
        return [
            // Legacy
            'shadow_runs'          => 30,
            'sustained_lift_ratio' => 0.15,
            'slo_passed'           => true,
            'replay_court_passed'  => true,
            'scaffold_compliance'  => true,
            'overfit_detected'     => false,
            'give_back_delta'      => 0.00,
            'poison_delta'         => 0.00,
            // New gates
            'heldout_pass_rate'    => 0.90,
            'green_commit_rate'    => 0.95,
            'proxy_leak_rate'      => 0.05,
            'sample_count'         => 60,
            'quality_lift_delta'   => 0.10,
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

    // ── AC1: decision field ───────────────────────────────────────────────────

    public function test_decision_is_promote_when_all_gates_pass(): void
    {
        $r = $this->evaluate();

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertTrue($r['promote']);
    }

    public function test_decision_is_shadow_more_when_heldout_below_floor(): void
    {
        $r = $this->evaluate(['heldout_pass_rate' => 0.60]);

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
        $this->assertFalse($r['promote']);
    }

    public function test_decision_is_rollback_when_proxy_leak_above_ceiling(): void
    {
        $r = $this->evaluate(['proxy_leak_rate' => 0.20]);

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
        $this->assertFalse($r['promote']);
    }

    public function test_decision_is_rollback_when_quality_regression(): void
    {
        $r = $this->evaluate(['quality_lift_delta' => -0.05]);

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
    }

    // ── AC1: reasons and missing_evidence fields ──────────────────────────────

    public function test_reasons_and_missing_evidence_are_present_in_output(): void
    {
        $r = $this->evaluate();

        $this->assertArrayHasKey('reasons',          $r);
        $this->assertArrayHasKey('missing_evidence', $r);
    }

    public function test_promote_has_empty_reasons(): void
    {
        $r = $this->evaluate();

        $this->assertSame([], $r['reasons']);
        $this->assertSame([], $r['missing_evidence']);
    }

    public function test_heldout_pass_rate_below_floor_in_reasons_and_missing_evidence(): void
    {
        $r = $this->evaluate(['heldout_pass_rate' => 0.50]);

        $this->assertContains('heldout_pass_rate_below_floor', $r['reasons']);
        $reasonsStr = implode(' ', $r['missing_evidence']);
        $this->assertStringContainsString('heldout', $reasonsStr);
    }

    public function test_proxy_leak_ceiling_in_reasons_and_missing_evidence(): void
    {
        $r = $this->evaluate(['proxy_leak_rate' => 0.20]);

        $this->assertContains('proxy_leak_ceiling_breached', $r['reasons']);
        $this->assertStringContainsString('proxy', implode(' ', $r['missing_evidence']));
    }

    // ── AC1: new gate inputs ──────────────────────────────────────────────────

    public function test_green_commit_rate_below_floor_blocks(): void
    {
        $r = $this->evaluate(['green_commit_rate' => 0.70]);

        $this->assertFalse($r['promote']);
        $this->assertContains('green_commit_rate_below_floor', $r['blocking_reasons']);
    }

    public function test_low_sample_count_blocks(): void
    {
        $r = $this->evaluate(['sample_count' => 10]);

        $this->assertFalse($r['promote']);
        $this->assertContains('sample_count_insufficient', $r['blocking_reasons']);
    }

    public function test_quality_lift_delta_below_floor_blocks(): void
    {
        $r = $this->evaluate(['quality_lift_delta' => 0.02]);

        $this->assertFalse($r['promote']);
        $this->assertContains('quality_lift_delta_below_floor', $r['blocking_reasons']);
    }

    // ── AC2: never promote on pass-rate alone ─────────────────────────────────

    public function test_never_promote_when_sample_count_insufficient_even_if_pass_rate_perfect(): void
    {
        $r = $this->evaluate(['heldout_pass_rate' => 1.0, 'sample_count' => 5]);

        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertFalse($r['promote']);
    }

    public function test_never_promote_when_proxy_leak_above_ceiling_even_if_pass_rate_perfect(): void
    {
        $r = $this->evaluate(['heldout_pass_rate' => 1.0, 'proxy_leak_rate' => 0.50]);

        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
    }
}
