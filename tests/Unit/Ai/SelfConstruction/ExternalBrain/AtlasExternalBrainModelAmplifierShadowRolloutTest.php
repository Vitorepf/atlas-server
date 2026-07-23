<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelAmplifierShadowRollout;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainModelAmplifierShadowRolloutTest extends TestCase
{
    private AtlasExternalBrainModelAmplifierShadowRollout $rollout;

    protected function setUp(): void
    {
        $this->rollout = new AtlasExternalBrainModelAmplifierShadowRollout;
    }

    private function goodProposal(array $overrides = []): array
    {
        return array_merge([
            'quality_score'      => 0.80,
            'has_duplicate'      => false,
            'scaffold_compliant' => true,
            'replay_passes'      => true,
            'value_density'      => 0.75,
        ], $overrides);
    }

    private function nProposals(int $n, array $overrides = []): array
    {
        return array_fill(0, $n, $this->goodProposal($overrides));
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(3),
            'amplified_proposals' => $this->nProposals(3),
        ]);

        foreach (['schema', 'shadow_enabled', 'baseline_summary', 'amplified_summary', 'promotion_candidate', 'safety_findings'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainModelAmplifierShadowRollout::SCHEMA, $result['schema']);
    }

    // ── AC2: shadow proposals are not enqueued (pure output only) ────────────

    public function test_shadow_enabled_defaults_to_true(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(2),
            'amplified_proposals' => $this->nProposals(2),
        ]);

        $this->assertTrue($result['shadow_enabled']);
    }

    public function test_shadow_disabled_blocks_promotion(): void
    {
        $result = $this->rollout->evaluate([
            'shadow_enabled'      => false,
            'baseline_proposals'  => $this->nProposals(3),
            'amplified_proposals' => $this->nProposals(3, ['quality_score' => 0.99]),
        ]);

        $this->assertFalse($result['promotion_candidate']);
        $this->assertNotEmpty($result['safety_findings']);
    }

    // ── AC3: quality lift measurement ─────────────────────────────────────────

    public function test_amplified_with_higher_quality_can_be_promotion_candidate(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(3, ['quality_score' => 0.60, 'value_density' => 0.60]),
            'amplified_proposals' => $this->nProposals(3, ['quality_score' => 0.90, 'value_density' => 0.90]),
        ]);

        $this->assertTrue($result['promotion_candidate']);
        $this->assertSame([], $result['safety_findings']);
    }

    public function test_amplified_with_only_marginal_lift_is_not_promoted(): void
    {
        // 0.62 - 0.60 = 0.02 < LIFT_THRESHOLD (0.05)
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(3, ['quality_score' => 0.60]),
            'amplified_proposals' => $this->nProposals(3, ['quality_score' => 0.62]),
        ]);

        $this->assertFalse($result['promotion_candidate']);
    }

    // ── AC3: quality regression detection ────────────────────────────────────

    public function test_quality_regression_is_reported_as_safety_finding(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(3, ['quality_score' => 0.80]),
            'amplified_proposals' => $this->nProposals(3, ['quality_score' => 0.50]),
        ]);

        $findings = implode(' ', $result['safety_findings']);
        $this->assertStringContainsString('quality_regression', $findings);
        $this->assertFalse($result['promotion_candidate']);
    }

    // ── AC3: duplicate risk measurement ──────────────────────────────────────

    public function test_higher_duplicate_rate_in_amplified_is_a_safety_finding(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(2, ['has_duplicate' => false]),
            'amplified_proposals' => $this->nProposals(2, ['has_duplicate' => true, 'quality_score' => 0.99]),
        ]);

        $findings = implode(' ', $result['safety_findings']);
        $this->assertStringContainsString('duplicate_risk', $findings);
    }

    // ── AC3: scaffold compliance measurement ──────────────────────────────────

    public function test_scaffold_compliance_regression_is_a_safety_finding(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(2, ['scaffold_compliant' => true]),
            'amplified_proposals' => $this->nProposals(2, ['scaffold_compliant' => false, 'quality_score' => 0.99]),
        ]);

        $findings = implode(' ', $result['safety_findings']);
        $this->assertStringContainsString('scaffold_compliance', $findings);
    }

    // ── AC3: replay-court pass rate ───────────────────────────────────────────

    public function test_replay_court_regression_is_a_safety_finding(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(2, ['replay_passes' => true]),
            'amplified_proposals' => $this->nProposals(2, ['replay_passes' => false, 'quality_score' => 0.99]),
        ]);

        $findings = implode(' ', $result['safety_findings']);
        $this->assertStringContainsString('replay_court', $findings);
    }

    // ── AC3: value-density delta ──────────────────────────────────────────────

    public function test_value_density_regression_is_a_safety_finding(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(2, ['value_density' => 0.90]),
            'amplified_proposals' => $this->nProposals(2, ['value_density' => 0.50, 'quality_score' => 0.99]),
        ]);

        $findings = implode(' ', $result['safety_findings']);
        $this->assertStringContainsString('value_density', $findings);
    }

    // ── Summary aggregation ───────────────────────────────────────────────────

    public function test_baseline_summary_has_expected_keys(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(2),
            'amplified_proposals' => $this->nProposals(2),
        ]);

        foreach (['count', 'avg_quality_score', 'duplicate_rate', 'scaffold_compliance_rate', 'replay_pass_rate', 'avg_value_density'] as $k) {
            $this->assertArrayHasKey($k, $result['baseline_summary']);
            $this->assertArrayHasKey($k, $result['amplified_summary']);
        }
    }

    public function test_summary_count_matches_input_count(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(4),
            'amplified_proposals' => $this->nProposals(5),
        ]);

        $this->assertSame(4, $result['baseline_summary']['count']);
        $this->assertSame(5, $result['amplified_summary']['count']);
    }

    // ── Edge: empty amplified proposals ──────────────────────────────────────

    public function test_empty_amplified_is_not_promotion_candidate(): void
    {
        $result = $this->rollout->evaluate([
            'baseline_proposals'  => $this->nProposals(3),
            'amplified_proposals' => [],
        ]);

        $this->assertFalse($result['promotion_candidate']);
        $this->assertNotEmpty($result['safety_findings']);
    }

    // ── recommendRollout ────────────────────────────────────────────────────────

    public function test_recommend_rollout_has_required_keys(): void
    {
        $result = $this->rollout->recommendRollout([
            'baseline_proposals'  => $this->nProposals(3),
            'amplified_proposals' => $this->nProposals(3),
        ]);

        foreach (['schema', 'recommendation', 'reason', 'baseline_risk_rates', 'amplified_risk_rates', 'baseline_summary', 'amplified_summary', 'safety_findings'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainModelAmplifierShadowRollout::SCHEMA, $result['schema']);
    }

    public function test_shadow_disabled_recommends_keep_shadowing(): void
    {
        $result = $this->rollout->recommendRollout([
            'shadow_enabled'      => false,
            'baseline_proposals'  => $this->nProposals(3),
            'amplified_proposals' => $this->nProposals(3, ['quality_score' => 0.95]),
        ]);

        $this->assertSame('keep_shadowing', $result['recommendation']);
        $this->assertSame('shadow_disabled', $result['reason']);
    }

    public function test_amplified_with_clear_lift_and_no_risk_increase_promotes(): void
    {
        $result = $this->rollout->recommendRollout([
            'baseline_proposals'  => $this->nProposals(5, ['quality_score' => 0.70]),
            'amplified_proposals' => $this->nProposals(5, ['quality_score' => 0.90]),
        ]);

        $this->assertSame('promote', $result['recommendation']);
    }

    public function test_increased_proxy_risk_blocks_promotion_even_with_quality_lift(): void
    {
        $result = $this->rollout->recommendRollout([
            'baseline_proposals'  => $this->nProposals(5, ['quality_score' => 0.70, 'proxy_risk' => false]),
            'amplified_proposals' => $this->nProposals(5, ['quality_score' => 0.95, 'proxy_risk' => true]),
        ]);

        $this->assertSame('rollback', $result['recommendation']);
        $this->assertSame('candidate_increases_proxy_risk', $result['reason']);
    }

    public function test_increased_give_back_risk_blocks_promotion_even_with_quality_lift(): void
    {
        $result = $this->rollout->recommendRollout([
            'baseline_proposals'  => $this->nProposals(5, ['quality_score' => 0.70, 'give_back_risk' => false]),
            'amplified_proposals' => $this->nProposals(5, ['quality_score' => 0.95, 'give_back_risk' => true]),
        ]);

        $this->assertSame('rollback', $result['recommendation']);
        $this->assertSame('candidate_increases_give_back_risk', $result['reason']);
    }

    public function test_no_lift_and_no_risk_increase_keeps_shadowing(): void
    {
        $result = $this->rollout->recommendRollout([
            'baseline_proposals'  => $this->nProposals(5, ['quality_score' => 0.70]),
            'amplified_proposals' => $this->nProposals(5, ['quality_score' => 0.71]),
        ]);

        $this->assertSame('keep_shadowing', $result['recommendation']);
    }

    public function test_quality_regression_keeps_shadowing_via_safety_findings(): void
    {
        $result = $this->rollout->recommendRollout([
            'baseline_proposals'  => $this->nProposals(5, ['quality_score' => 0.80]),
            'amplified_proposals' => $this->nProposals(5, ['quality_score' => 0.50]),
        ]);

        $this->assertSame('keep_shadowing', $result['recommendation']);
        $this->assertSame('safety_findings_present', $result['reason']);
    }

    public function test_risk_increase_outranks_safety_findings_reason(): void
    {
        $result = $this->rollout->recommendRollout([
            'baseline_proposals'  => $this->nProposals(5, ['quality_score' => 0.80, 'proxy_risk' => false]),
            'amplified_proposals' => $this->nProposals(5, ['quality_score' => 0.50, 'proxy_risk' => true]),
        ]);

        $this->assertSame('rollback', $result['recommendation']);
    }

    public function test_does_not_mutate_input_proposals(): void
    {
        $baseline = $this->nProposals(2);
        $amplified = $this->nProposals(2, ['quality_score' => 0.9]);
        $baselineCopy = $baseline;
        $amplifiedCopy = $amplified;

        $this->rollout->recommendRollout(['baseline_proposals' => $baseline, 'amplified_proposals' => $amplified]);

        $this->assertSame($baselineCopy, $baseline);
        $this->assertSame($amplifiedCopy, $amplified);
    }

    // ── AC2: shadow proposals are never enqueued — static source proof ────────

    public function test_source_never_calls_enqueue_dispatch_or_io(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainModelAmplifierShadowRollout.php');

        foreach (['->enqueue(', 'Queue::', 'DB::', 'Storage::', 'file_put_contents', 'shell_exec', 'proc_open', 'dispatch(', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "shadow rollout must never {$forbidden}");
        }
    }

    // ── heldOutPromotionEvidence: promotion_evidence, held_out_summary, safety_findings ──

    public function test_held_out_sample_required_for_promotion(): void
    {
        $r = $this->rollout->heldOutPromotionEvidence([
            'held_out_sample_count' => 0,
            'quality_lift' => 0.1,
        ]);
        $this->assertFalse($r['promotion_candidate']);
        $this->assertContains('missing_held_out_sample: cannot promote without held-out evidence', $r['safety_findings']);
        $this->assertArrayHasKey('promotion_evidence', $r);
        $this->assertArrayHasKey('held_out_summary', $r);
    }

    public function test_proxy_leak_increase_blocks_promotion(): void
    {
        $r = $this->rollout->heldOutPromotionEvidence([
            'held_out_sample_count' => 10,
            'held_out_pass_count' => 8,
            'proxy_leak_rate' => 0.15,
            'baseline_proxy_leak_rate' => 0.05,
            'quality_lift' => 0.1,
        ]);
        $this->assertFalse($r['promotion_candidate']);
        $this->assertNotEmpty(array_filter($r['safety_findings'], fn ($f) => str_contains($f, 'proxy_leak_increase')));
    }

    public function test_duplicate_rate_increase_blocks_promotion(): void
    {
        $r = $this->rollout->heldOutPromotionEvidence([
            'held_out_sample_count' => 10,
            'held_out_pass_count' => 8,
            'duplicate_rate' => 0.20,
            'baseline_duplicate_rate' => 0.10,
            'quality_lift' => 0.1,
        ]);
        $this->assertFalse($r['promotion_candidate']);
        $this->assertNotEmpty(array_filter($r['safety_findings'], fn ($f) => str_contains($f, 'duplicate_rate_increase')));
    }

    public function test_valid_held_out_evidence_allows_promotion(): void
    {
        $r = $this->rollout->heldOutPromotionEvidence([
            'held_out_sample_count' => 20,
            'held_out_pass_count' => 18,
            'proxy_leak_rate' => 0.02,
            'baseline_proxy_leak_rate' => 0.05,
            'duplicate_rate' => 0.05,
            'baseline_duplicate_rate' => 0.10,
            'quality_lift' => 0.15,
        ]);
        $this->assertTrue($r['promotion_candidate']);
        $this->assertSame([], $r['safety_findings']);
        $this->assertSame(20, $r['held_out_summary']['held_out_sample_count']);
        $this->assertSame(0.9, $r['held_out_summary']['held_out_pass_rate']);
    }
}
