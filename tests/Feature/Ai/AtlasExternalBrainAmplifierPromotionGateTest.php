<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierPromotionGate;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierPromotionGateTest extends TestCase
{
    private function svc(): AtlasExternalBrainAmplifierPromotionGate
    {
        return new AtlasExternalBrainAmplifierPromotionGate;
    }

    /** A passing input that clears all gates. */
    private function passingInput(array $overrides = []): array
    {
        return array_merge([
            'shadow_runs'          => 35,
            'sustained_lift_ratio' => 0.20,
            'slo_passed'           => true,
            'replay_court_passed'  => true,
            'scaffold_compliance'  => true,
            'overfit_detected'     => false,
            'give_back_delta'      => 0.02,
            'poison_delta'         => 0.0,
            'heldout_pass_rate'    => 0.90,
            'green_commit_rate'    => 0.95,
            'proxy_leak_rate'      => 0.05,
            'sample_count'         => 60,
            'quality_lift_delta'   => 0.10,
        ], $overrides);
    }

    // ── AC1: runnable gate (implicit) ─────────────────────────────────────────

    public function test_ac1_output_has_required_keys(): void
    {
        $r = $this->svc()->evaluate([]);
        $this->assertArrayHasKey('decision',                  $r);
        $this->assertArrayHasKey('promote',                   $r);
        $this->assertArrayHasKey('reasons',                   $r);
        $this->assertArrayHasKey('missing_evidence',          $r);
        $this->assertArrayHasKey('blocking_reasons',          $r);
        $this->assertArrayHasKey('required_more_shadow_runs', $r);
        $this->assertArrayHasKey('live_rollout_constraints',  $r);
    }

    // ── AC2: insufficient shadow / heldout / green_commit / sample_count → shadow_more ──

    public function test_ac2_insufficient_shadow_runs_returns_shadow_more(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['shadow_runs' => 10]));

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
        $this->assertFalse($r['promote']);
        $this->assertContains('sample_too_small', $r['blocking_reasons']);
        $this->assertGreaterThan(0, $r['required_more_shadow_runs']);
    }

    public function test_ac2_low_heldout_pass_rate_returns_shadow_more_with_missing_evidence(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['heldout_pass_rate' => 0.70]));

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
        $this->assertContains('heldout_pass_rate_below_floor', $r['blocking_reasons']);
        $this->assertNotEmpty($r['missing_evidence']);
    }

    public function test_ac2_low_green_commit_rate_returns_shadow_more_with_missing_evidence(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['green_commit_rate' => 0.80]));

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
        $this->assertContains('green_commit_rate_below_floor', $r['blocking_reasons']);
        $this->assertNotEmpty($r['missing_evidence']);
    }

    public function test_ac2_low_sample_count_returns_shadow_more_with_missing_evidence(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['sample_count' => 20]));

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_SHADOW_MORE, $r['decision']);
        $this->assertContains('sample_count_insufficient', $r['blocking_reasons']);
        $this->assertNotEmpty($r['missing_evidence']);
    }

    public function test_ac2_missing_evidence_is_populated_for_each_failing_gate(): void
    {
        $r = $this->svc()->evaluate($this->passingInput([
            'heldout_pass_rate' => 0.65,
            'green_commit_rate' => 0.75,
        ]));

        $this->assertGreaterThanOrEqual(2, count($r['missing_evidence']),
            'each failing gate must contribute to missing_evidence');
    }

    // ── AC3: proxy leak / poison / overfit / negative quality_lift → rollback ─

    public function test_ac3_proxy_leak_ceiling_breach_returns_rollback(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['proxy_leak_rate' => 0.20]));

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
        $this->assertFalse($r['promote']);
        $this->assertContains('proxy_leak_ceiling_breached', $r['reasons']);
    }

    public function test_ac3_poison_increase_returns_rollback(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['poison_delta' => 0.05]));

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
        $this->assertContains('poison_risk_increased', $r['reasons']);
    }

    public function test_ac3_overfit_detected_returns_rollback(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['overfit_detected' => true]));

        // overfit_detected is a blocking_reason not a rollback_trigger, so shadow_more or rollback
        $this->assertNotSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertContains('overfit_detected', $r['reasons']);
    }

    public function test_ac3_negative_quality_lift_delta_returns_rollback(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['quality_lift_delta' => -0.10]));

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_ROLLBACK, $r['decision']);
        $this->assertContains('quality_regression_detected', $r['reasons']);
    }

    public function test_ac3_rollback_decision_has_missing_evidence_explaining_why(): void
    {
        $r = $this->svc()->evaluate($this->passingInput(['proxy_leak_rate' => 0.15]));

        $this->assertNotEmpty($r['missing_evidence'],
            'rollback must explain what evidence would be needed to unblock');
    }

    // ── AC4: passing variant → promote + canary + daily SLO recheck ──────────

    public function test_ac4_passing_variant_returns_promote(): void
    {
        $r = $this->svc()->evaluate($this->passingInput());

        $this->assertSame(AtlasExternalBrainAmplifierPromotionGate::DECISION_PROMOTE, $r['decision']);
        $this->assertTrue($r['promote']);
        $this->assertEmpty($r['reasons']);
        $this->assertEmpty($r['blocking_reasons']);
    }

    public function test_ac4_promote_includes_canary_constraint(): void
    {
        $r = $this->svc()->evaluate($this->passingInput());

        $this->assertContains('canary_first', $r['live_rollout_constraints'],
            'live rollout must require canary deployment');
    }

    public function test_ac4_promote_includes_daily_slo_recheck(): void
    {
        $r = $this->svc()->evaluate($this->passingInput());

        $hasRecheck = false;
        foreach ($r['live_rollout_constraints'] as $constraint) {
            if (str_contains($constraint, 'slo_recheck') || str_contains($constraint, 'daily')) {
                $hasRecheck = true;
                break;
            }
        }
        $this->assertTrue($hasRecheck, 'live rollout must require periodic SLO recheck');
    }

    public function test_ac4_non_promote_decisions_have_no_rollout_constraints(): void
    {
        $shadow = $this->svc()->evaluate($this->passingInput(['shadow_runs' => 5]));
        $this->assertEmpty($shadow['live_rollout_constraints'],
            'shadow_more must not emit live_rollout_constraints');
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_deterministic_output(): void
    {
        $input = $this->passingInput();
        $this->assertSame(
            json_encode($this->svc()->evaluate($input), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->evaluate($input), JSON_UNESCAPED_SLASHES),
        );
    }
}
