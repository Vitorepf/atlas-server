<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tokens;

use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Tests\TestCase;

/**
 * ATER (Token Economy) Phase 1 — budget policy contract.
 *
 * Cobre a logica pura (in-memory) do AtlasTokenEconomyBudgetPolicyService:
 *  - budget canonico por flow x risk + fallback degrade-safe
 *  - evaluateVariants: must_keep_coverage < 1.0 NUNCA e elegivel (invariante imune)
 *  - selecao por quality, depois custo; blocked quando nada cabe
 *  - estimateCost deterministico com clamp de negativos
 *  - reuseCredit por hash de contexto
 */
class AtlasTokenEconomyBudgetPolicyServiceTest extends TestCase
{
    private AtlasTokenEconomyBudgetPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasTokenEconomyBudgetPolicyService;
    }

    public function test_budget_returns_canonical_envelope_for_known_flow_and_risk(): void
    {
        $budget = $this->service->budget(
            AtlasTokenEconomyBudgetPolicyService::FLOW_ATLAS_DEV,
            AtlasTokenEconomyBudgetPolicyService::RISK_HIGH,
        );

        $this->assertSame('atlas.token_economy.budget.v1', $budget['schema_version']);
        $this->assertSame('atlas_dev', $budget['flow_id']);
        $this->assertSame('high', $budget['risk_level']);
        $this->assertSame(64_000, $budget['input_tokens_max']);
        $this->assertSame(16_000, $budget['output_tokens_max']);
        $this->assertSame(8.0, $budget['cost_units_max']);
    }

    public function test_unknown_flow_and_risk_degrade_to_default_low_instead_of_failing(): void
    {
        $budget = $this->service->budget('flow_que_nao_existe', 'risco_invalido');

        $this->assertSame('default', $budget['flow_id']);
        $this->assertSame('low', $budget['risk_level']);
        $this->assertSame(8_000, $budget['input_tokens_max']);
    }

    public function test_variant_with_must_keep_coverage_below_one_is_never_eligible(): void
    {
        $result = $this->service->evaluateVariants([
            ['name' => 'compact_but_lossy', 'input_tokens' => 100, 'output_tokens_estimate' => 50, 'must_keep_coverage' => 0.9],
        ]);

        $this->assertNull($result['selected']);
        $this->assertSame('no_variant_fits_budget_with_must_keep_intact', $result['blocked_reason']);
        $this->assertFalse($result['evaluated'][0]['eligible']);
        $this->assertContains('must_keep_coverage_below_1', $result['evaluated'][0]['rejection_reasons']);
    }

    public function test_over_budget_variants_carry_specific_rejection_reasons(): void
    {
        // default/low: input max 8k, output max 2k.
        $result = $this->service->evaluateVariants([
            ['name' => 'too_big', 'input_tokens' => 9_000, 'output_tokens_estimate' => 3_000],
        ]);

        $reasons = $result['evaluated'][0]['rejection_reasons'];
        $this->assertContains('input_tokens_over_budget', $reasons);
        $this->assertContains('output_tokens_over_budget', $reasons);
        $this->assertNull($result['selected']);
    }

    public function test_selection_prefers_higher_quality_then_lower_cost(): void
    {
        $result = $this->service->evaluateVariants([
            ['name' => 'cheap_low_quality', 'input_tokens' => 1_000, 'output_tokens_estimate' => 100, 'quality_score' => 0.6],
            ['name' => 'best_quality', 'input_tokens' => 4_000, 'output_tokens_estimate' => 500, 'quality_score' => 0.9],
            ['name' => 'same_quality_cheaper', 'input_tokens' => 2_000, 'output_tokens_estimate' => 200, 'quality_score' => 0.9],
        ]);

        $this->assertSame('atlas.token_economy.consumption.v1', $result['schema_version']);
        $this->assertNotNull($result['selected']);
        $this->assertNull($result['blocked_reason']);
        // Empate em quality 0.9 -> desempata pelo menor cost_units.
        $this->assertSame('same_quality_cheaper', $result['selected']['name']);
    }

    public function test_estimate_cost_is_deterministic_and_clamps_negatives(): void
    {
        // (input + output*4) / 100k
        $this->assertSame(1.0, $this->service->estimateCost(60_000, 10_000));
        $this->assertSame(0.0, $this->service->estimateCost(-500, -500));
        $this->assertSame(
            $this->service->estimateCost(12_345, 678),
            $this->service->estimateCost(12_345, 678),
        );
    }

    public function test_reuse_credit_saves_tokens_only_on_cache_hit(): void
    {
        $hit = $this->service->reuseCredit('hash-a', 5_000, ['hash-a', 'hash-b']);
        $miss = $this->service->reuseCredit('hash-c', 5_000, ['hash-a', 'hash-b']);

        $this->assertSame('atlas.token_economy.reuse_credit.v1', $hit['schema_version']);
        $this->assertTrue($hit['reused']);
        $this->assertSame(5_000, $hit['saved_tokens']);
        $this->assertFalse($miss['reused']);
        $this->assertSame(0, $miss['saved_tokens']);
    }
}
