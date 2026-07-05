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
            // Held-out diversity gates
            'heldout_task_families'         => ['bugfix', 'refactor', 'test_authoring'],
            'recent_muscle_outcome_windows'  => ['2026-06-24..2026-06-30'],
            // Shadow baseline-beat gates
            'success_rate'                  => 0.90,
            'impact_score'                  => 0.80,
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
        $this->assertContains('poison_risk_increased', $r['rollback_triggers']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
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

    // ── output has rollback_triggers as its own field ───────────────────────────

    public function test_output_has_rollback_triggers_key(): void
    {
        $r = $this->evaluate();

        $this->assertArrayHasKey('rollback_triggers', $r);
        $this->assertSame([], $r['rollback_triggers']);
    }

    public function test_proxy_leak_ceiling_breach_appears_in_rollback_triggers_and_decision_rollback(): void
    {
        $r = $this->evaluate(['proxy_leak_rate' => 0.50]);

        $this->assertContains('proxy_leak_ceiling_breached', $r['rollback_triggers']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
        $this->assertFalse($r['promote']);
    }

    public function test_negative_quality_lift_appears_in_rollback_triggers_and_decision_rollback(): void
    {
        $r = $this->evaluate(['quality_lift_delta' => -0.05]);

        $this->assertContains('quality_regression_detected', $r['rollback_triggers']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
        $this->assertFalse($r['promote']);
    }

    // ── AC3: missing mandatory evidence keeps shadow_more even with high lift_ratio ──

    public function test_missing_evidence_keeps_shadow_more_despite_high_lift_ratio(): void
    {
        $r = $this->evaluate([
            'sustained_lift_ratio' => 0.95, // very high legacy lift
            'sample_count'         => 0,    // no shadow evidence at all
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
        $this->assertFalse($r['promote']);
        $this->assertNotEmpty($r['missing_evidence']);
    }

    public function test_missing_heldout_evidence_keeps_shadow_more_despite_high_lift_ratio(): void
    {
        $r = $this->evaluate([
            'sustained_lift_ratio' => 0.95,
            'heldout_pass_rate'    => 0.0,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
        $this->assertContains('heldout_test_suite_with_pass_rate_above_floor', $r['missing_evidence']);
    }

    // ── AC4: proxy leak, negative lift, or poison_delta → rollback, never promote ──

    public function test_proxy_leak_never_promotes_even_with_perfect_everything_else(): void
    {
        $r = $this->evaluate(['proxy_leak_rate' => 0.99]);

        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
    }

    public function test_negative_quality_lift_never_promotes(): void
    {
        $r = $this->evaluate(['quality_lift_delta' => -0.01]);

        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
    }

    public function test_poison_delta_never_promotes(): void
    {
        $r = $this->evaluate(['poison_delta' => 0.001]);

        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
    }

    public function test_rollback_wins_over_shadow_more_when_both_present(): void
    {
        // Missing evidence (sample_count=0) AND a rollback trigger (poison) both present.
        $r = $this->evaluate(['sample_count' => 0, 'poison_delta' => 0.01]);

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
        $this->assertNotEmpty($r['rollback_triggers']);
        $this->assertNotEmpty($r['blocking_reasons']);
    }

    // ── AC: heldout task family diversity ─────────────────────────────────────

    public function test_promotion_blocked_when_fewer_than_three_heldout_task_families(): void
    {
        $r = $this->evaluate(['heldout_task_families' => ['bugfix', 'refactor']]);

        $this->assertFalse($r['promote']);
        $this->assertContains('heldout_task_family_diversity_insufficient', $r['blocking_reasons']);
        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
    }

    public function test_promotion_blocked_when_heldout_task_families_missing(): void
    {
        $r = $this->evaluate(['heldout_task_families' => []]);

        $this->assertFalse($r['promote']);
        $this->assertContains('heldout_task_family_diversity_insufficient', $r['blocking_reasons']);
    }

    public function test_promotion_allowed_with_three_distinct_heldout_task_families(): void
    {
        $r = $this->evaluate(['heldout_task_families' => ['bugfix', 'refactor', 'test_authoring']]);

        $this->assertNotContains('heldout_task_family_diversity_insufficient', $r['blocking_reasons']);
    }

    public function test_duplicate_heldout_task_families_do_not_count_toward_diversity(): void
    {
        $r = $this->evaluate(['heldout_task_families' => ['bugfix', 'bugfix', 'bugfix']]);

        $this->assertFalse($r['promote']);
        $this->assertContains('heldout_task_family_diversity_insufficient', $r['blocking_reasons']);
    }

    // ── AC: recent muscle outcome window ────────────────────────────────────────

    public function test_promotion_blocked_when_recent_muscle_outcome_windows_missing(): void
    {
        $r = $this->evaluate(['recent_muscle_outcome_windows' => []]);

        $this->assertFalse($r['promote']);
        $this->assertContains('recent_muscle_outcome_window_missing', $r['blocking_reasons']);
        $this->assertContains('at_least_one_recent_muscle_outcome_window', $r['missing_evidence']);
    }

    public function test_promotion_blocked_when_recent_muscle_outcome_windows_key_absent(): void
    {
        $input = $this->passing();
        unset($input['recent_muscle_outcome_windows']);
        $r = $this->svc()->evaluate($input);

        $this->assertFalse($r['promote']);
        $this->assertContains('recent_muscle_outcome_window_missing', $r['blocking_reasons']);
    }

    // ── AC: passing diverse held-out sample emits canary_by_task_family ────────

    public function test_passing_diverse_heldout_sample_emits_canary_by_task_family_constraint(): void
    {
        $r = $this->evaluate();

        $this->assertTrue($r['promote']);
        $this->assertContains('canary_by_task_family', $r['live_rollout_constraints']);
    }

    public function test_evaluate_is_deterministic(): void
    {
        $input = $this->passing();
        $a = $this->svc()->evaluate($input);
        $b = $this->svc()->evaluate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── success_rate gates ──────────────────────────────────────────────────

    public function test_promotion_blocked_when_success_rate_below_threshold(): void
    {
        $r = $this->evaluate(['success_rate' => 0.80]);

        $this->assertFalse($r['promote']);
        $this->assertContains('success_rate_below_threshold', $r['blocking_reasons']);
        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
    }

    public function test_promotion_allowed_when_success_rate_at_threshold(): void
    {
        $r = $this->evaluate(['success_rate' => 0.85]);

        $this->assertNotContains('success_rate_below_threshold', $r['blocking_reasons']);
    }

    public function test_promotion_blocked_when_impact_score_below_threshold(): void
    {
        $r = $this->evaluate(['impact_score' => 0.60]);

        $this->assertFalse($r['promote']);
        $this->assertContains('impact_score_below_threshold', $r['blocking_reasons']);
        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
    }

    public function test_promotion_allowed_when_impact_score_at_threshold(): void
    {
        $r = $this->evaluate(['impact_score' => 0.70]);

        $this->assertNotContains('impact_score_below_threshold', $r['blocking_reasons']);
    }

    public function test_high_success_and_impact_with_everything_else_passing_promotes(): void
    {
        $r = $this->evaluate(['success_rate' => 1.0, 'impact_score' => 1.0]);

        $this->assertTrue($r['promote']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertSame([], $r['reasons']);
    }

    // ── next_action field ────────────────────────────────────────────────────

    public function test_next_action_is_promote_default_when_all_gates_pass(): void
    {
        $r = $this->evaluate();

        $this->assertSame('promote_default', $r['next_action']);
    }

    public function test_next_action_is_retry_when_shadow_more(): void
    {
        $r = $this->evaluate(['sample_count' => 5]);

        $this->assertSame('retry', $r['next_action']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
    }

    public function test_next_action_is_rollback_when_proxy_leak_above_ceiling(): void
    {
        $r = $this->evaluate(['proxy_leak_rate' => 0.50]);

        $this->assertSame('rollback', $r['next_action']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
    }

    public function test_next_action_is_rollback_when_poison_increased(): void
    {
        $r = $this->evaluate(['poison_delta' => 0.01]);

        $this->assertSame('rollback', $r['next_action']);
        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
    }

    public function test_next_action_present_in_output(): void
    {
        $r = $this->svc()->evaluate([]);

        $this->assertArrayHasKey('next_action', $r);
    }

    public function test_success_rate_below_threshold_appears_in_reasons_and_missing_evidence(): void
    {
        $r = $this->evaluate(['success_rate' => 0.50]);

        $this->assertContains('success_rate_below_threshold', $r['reasons']);
        $reasonsStr = implode(' ', $r['missing_evidence']);
        $this->assertStringContainsString('success_rate', $reasonsStr);
    }

    public function test_impact_score_below_threshold_appears_in_reasons_and_missing_evidence(): void
    {
        $r = $this->evaluate(['impact_score' => 0.40]);

        $this->assertContains('impact_score_below_threshold', $r['reasons']);
        $reasonsStr = implode(' ', $r['missing_evidence']);
        $this->assertStringContainsString('impact_score', $reasonsStr);
    }

    public function test_high_throughput_with_worsened_give_back_blocks_even_with_perfect_success_rate(): void
    {
        // Even if success_rate and impact_score are perfect, worsened give_back must block
        $r = $this->evaluate([
            'success_rate'    => 1.0,
            'impact_score'    => 1.0,
            'give_back_delta' => 0.06, // above MAX_GIVE_BACK_DELTA
        ]);

        $this->assertFalse($r['promote']);
        $this->assertContains('give_back_risk_increased', $r['blocking_reasons']);
        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
    }
}
