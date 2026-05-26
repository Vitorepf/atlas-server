<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — ATER Phase 1 tests.
 */
class AtlasTokenEconomyRuntimeServiceTest extends TestCase
{
    public function test_budget_returns_canonical_envelope_default(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;
        $b = $s->budget();

        $this->assertSame('atlas.token_economy.budget.v1', $b['schema_version']);
        $this->assertSame('default', $b['flow_id']);
        $this->assertSame('low', $b['risk_level']);
        $this->assertSame(8_000, $b['input_tokens_max']);
        $this->assertSame(2_000, $b['output_tokens_max']);
        $this->assertEqualsWithDelta(1.0, $b['cost_units_max'], 0.001);
    }

    public function test_budget_scales_with_risk(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;
        $low = $s->budget('atlas_dev', 'low');
        $high = $s->budget('atlas_dev', 'high');
        $irreversible = $s->budget('atlas_dev', 'irreversible');

        $this->assertLessThan($high['input_tokens_max'], $low['input_tokens_max']);
        $this->assertLessThan($irreversible['input_tokens_max'], $high['input_tokens_max']);
    }

    public function test_budget_falls_back_to_default_for_unknown_flow(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;
        $b = $s->budget('unknown_flow', 'low');

        $this->assertSame('default', $b['flow_id']);
    }

    public function test_budget_falls_back_to_low_risk_for_unknown_risk(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;
        $b = $s->budget('atlas_dev', 'extreme_danger');

        $this->assertSame('low', $b['risk_level']);
    }

    public function test_estimate_cost_increases_with_tokens(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;

        $cheap = $s->estimateCost(1000, 100);
        $expensive = $s->estimateCost(10_000, 1_000);

        $this->assertGreaterThan(0, $cheap);
        $this->assertGreaterThan($cheap, $expensive);
    }

    public function test_estimate_cost_handles_zero_and_negative(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;
        $this->assertSame(0.0, $s->estimateCost(0, 0));
        $this->assertSame(0.0, $s->estimateCost(-100, -100));
    }

    public function test_evaluate_variants_selects_best_quality_within_budget(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;

        $result = $s->evaluateVariants([
            ['name' => 'A', 'input_tokens' => 5000, 'output_tokens_estimate' => 500, 'quality_score' => 0.7, 'must_keep_coverage' => 1.0],
            ['name' => 'B', 'input_tokens' => 6000, 'output_tokens_estimate' => 800, 'quality_score' => 0.92, 'must_keep_coverage' => 1.0],
            ['name' => 'C', 'input_tokens' => 4000, 'output_tokens_estimate' => 400, 'quality_score' => 0.85, 'must_keep_coverage' => 1.0],
        ], 'default', 'low');

        $this->assertSame('atlas.token_economy.consumption.v1', $result['schema_version']);
        $this->assertNull($result['blocked_reason']);
        $this->assertSame('B', $result['selected']['name'], 'B tem maior quality.');
    }

    public function test_evaluate_variants_blocks_must_keep_coverage_below_1(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;

        $result = $s->evaluateVariants([
            ['name' => 'compressed', 'input_tokens' => 1000, 'output_tokens_estimate' => 100, 'quality_score' => 0.95, 'must_keep_coverage' => 0.8],
        ], 'default', 'low');

        $this->assertSame('no_variant_fits_budget_with_must_keep_intact', $result['blocked_reason']);
        $this->assertNull($result['selected']);
        $this->assertContains('must_keep_coverage_below_1', $result['evaluated'][0]['rejection_reasons']);
    }

    public function test_evaluate_variants_blocks_over_budget(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;

        $result = $s->evaluateVariants([
            ['name' => 'huge', 'input_tokens' => 999_999, 'output_tokens_estimate' => 99_999, 'quality_score' => 0.99, 'must_keep_coverage' => 1.0],
        ], 'default', 'low');

        $this->assertSame('no_variant_fits_budget_with_must_keep_intact', $result['blocked_reason']);
        $this->assertContains('input_tokens_over_budget', $result['evaluated'][0]['rejection_reasons']);
    }

    public function test_reuse_credit_detects_cache_hit(): void
    {
        $s = new AtlasTokenEconomyBudgetPolicyService;

        $hit = $s->reuseCredit('hash-A', 5000, ['hash-X', 'hash-A', 'hash-Y']);
        $miss = $s->reuseCredit('hash-Z', 5000, ['hash-X', 'hash-A', 'hash-Y']);

        $this->assertTrue($hit['reused']);
        $this->assertSame(5000, $hit['saved_tokens']);

        $this->assertFalse($miss['reused']);
        $this->assertSame(0, $miss['saved_tokens']);
    }

    public function test_static_validators(): void
    {
        $this->assertTrue(AtlasTokenEconomyBudgetPolicyService::isValidFlow('atlas_dev'));
        $this->assertTrue(AtlasTokenEconomyBudgetPolicyService::isValidFlow('default'));
        $this->assertFalse(AtlasTokenEconomyBudgetPolicyService::isValidFlow('unknown'));

        $this->assertTrue(AtlasTokenEconomyBudgetPolicyService::isValidRisk('low'));
        $this->assertTrue(AtlasTokenEconomyBudgetPolicyService::isValidRisk('irreversible'));
        $this->assertFalse(AtlasTokenEconomyBudgetPolicyService::isValidRisk('extreme'));
    }
}
