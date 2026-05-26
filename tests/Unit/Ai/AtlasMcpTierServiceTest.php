<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Mcp\AtlasMcpTierService;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 4 (Progressive Disclosure MCP).
 *
 * Cobre classificacao de tier + manifest + savings estimate do service.
 */
class AtlasMcpTierServiceTest extends TestCase
{
    public function test_tier_constants_are_canonical(): void
    {
        $this->assertSame(1, AtlasMcpTierService::TIER_DISCOVERY);
        $this->assertSame(2, AtlasMcpTierService::TIER_CONTEXT);
        $this->assertSame(3, AtlasMcpTierService::TIER_DETAIL);
    }

    public function test_is_valid_tier_accepts_1_2_3_and_rejects_others(): void
    {
        $this->assertTrue(AtlasMcpTierService::isValidTier(1));
        $this->assertTrue(AtlasMcpTierService::isValidTier(2));
        $this->assertTrue(AtlasMcpTierService::isValidTier(3));
        $this->assertFalse(AtlasMcpTierService::isValidTier(0));
        $this->assertFalse(AtlasMcpTierService::isValidTier(4));
        $this->assertFalse(AtlasMcpTierService::isValidTier(-1));
    }

    public function test_is_valid_cost_class_accepts_low_medium_high(): void
    {
        $this->assertTrue(AtlasMcpTierService::isValidCostClass('low'));
        $this->assertTrue(AtlasMcpTierService::isValidCostClass('medium'));
        $this->assertTrue(AtlasMcpTierService::isValidCostClass('high'));
        $this->assertFalse(AtlasMcpTierService::isValidCostClass('LOW'));
        $this->assertFalse(AtlasMcpTierService::isValidCostClass(''));
        $this->assertFalse(AtlasMcpTierService::isValidCostClass('unknown'));
    }

    public function test_get_tier_returns_canonical_envelope_for_known_tool(): void
    {
        $service = new AtlasMcpTierService;

        $entry = $service->getTier('atlas_memory_search_brief');

        $this->assertNotNull($entry);
        $this->assertSame('atlas.mcp.tier.v1', $entry['schema_version']);
        $this->assertSame(1, $entry['tier']);
        $this->assertSame('low', $entry['cost_class']);
        $this->assertSame(50, $entry['token_estimate']);
        $this->assertFalse($entry['requires_anchor']);
        $this->assertFalse($entry['batch']);
        $this->assertTrue($entry['provider_safe']);
        $this->assertSame('atlas_memory_search_brief', $entry['tool_name']);
    }

    public function test_get_tier_returns_null_for_unknown_tool(): void
    {
        $service = new AtlasMcpTierService;

        $this->assertNull($service->getTier('some_unknown_tool'));
    }

    public function test_tier_2_tools_require_anchor(): void
    {
        $service = new AtlasMcpTierService;

        $timeline = $service->getTier('atlas_memory_timeline');

        $this->assertNotNull($timeline);
        $this->assertSame(2, $timeline['tier']);
        $this->assertTrue($timeline['requires_anchor']);
        $this->assertSame('medium', $timeline['cost_class']);
    }

    public function test_tier_3_tools_are_batch(): void
    {
        $service = new AtlasMcpTierService;

        $getFull = $service->getTier('atlas_memory_get_full');

        $this->assertNotNull($getFull);
        $this->assertSame(3, $getFull['tier']);
        $this->assertTrue($getFull['batch']);
        $this->assertSame('high', $getFull['cost_class']);
        $this->assertGreaterThanOrEqual(500, $getFull['token_estimate']);
    }

    public function test_tier_manifest_groups_tools_by_tier(): void
    {
        $service = new AtlasMcpTierService;

        $manifest = $service->tierManifest();

        $this->assertSame('atlas.mcp.tier.v1', $manifest['schema_version']);
        $this->assertGreaterThan(0, $manifest['total_tools']);
        $this->assertArrayHasKey(1, $manifest['tiers']);
        $this->assertArrayHasKey(2, $manifest['tiers']);
        $this->assertArrayHasKey(3, $manifest['tiers']);

        // Cada tier tem pelo menos um tool.
        $this->assertNotEmpty($manifest['tiers'][1]);
        $this->assertNotEmpty($manifest['tiers'][2]);
        $this->assertNotEmpty($manifest['tiers'][3]);

        $this->assertStringContainsString('Tier 1', $manifest['workflow_hint']);
        $this->assertStringContainsString('Tier 3', $manifest['workflow_hint']);
    }

    public function test_tools_in_tier_returns_only_that_tier(): void
    {
        $service = new AtlasMcpTierService;

        $tier1 = $service->toolsInTier(1);
        $tier3 = $service->toolsInTier(3);

        $this->assertContains('atlas_memory_search_brief', $tier1);
        $this->assertContains('atlas_memory_get_full', $tier3);
        $this->assertNotContains('atlas_memory_search_brief', $tier3);
        $this->assertNotContains('atlas_memory_get_full', $tier1);
    }

    public function test_estimate_savings_with_default_scenario(): void
    {
        $service = new AtlasMcpTierService;

        $savings = $service->estimateSavings();

        $this->assertArrayHasKey('full_dump_tokens', $savings);
        $this->assertArrayHasKey('progressive_tokens', $savings);
        $this->assertArrayHasKey('savings_pct', $savings);

        // Cenario default = 5 items -> full dump 4000 tokens.
        $this->assertSame(4000, $savings['full_dump_tokens']);
        $this->assertGreaterThan(0, $savings['progressive_tokens']);
        $this->assertGreaterThan(0, $savings['savings_pct'], 'progressive deve economizar tokens vs full dump em scenarios tipicos.');
    }

    public function test_estimate_savings_scales_with_count(): void
    {
        $service = new AtlasMcpTierService;

        $small = $service->estimateSavings(['full_dump_count' => 3]);
        $large = $service->estimateSavings(['full_dump_count' => 10]);

        $this->assertLessThan($large['full_dump_tokens'], $small['full_dump_tokens']);
        $this->assertLessThan($large['progressive_tokens'], $small['progressive_tokens']);
    }
}
