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
}
