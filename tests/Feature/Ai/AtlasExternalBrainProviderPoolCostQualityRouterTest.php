<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCostQualityRouter;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolCostQualityRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainProviderPoolCostQualityRouter
    {
        return new AtlasExternalBrainProviderPoolCostQualityRouter;
    }

    private function cheapPool(array $overrides = []): array
    {
        return array_merge([
            'pool_id' => 'cheap-pool',
            'cost_tier' => 'cheap',
            'model_strength' => 0.5,
            'historical_success_rate' => 0.9,
            'give_back_rate' => 0.05,
            'proven' => true,
            'optional_provider_ready' => true,
        ], $overrides);
    }

    private function strongPool(array $overrides = []): array
    {
        return array_merge([
            'pool_id' => 'strong-pool',
            'cost_tier' => 'strong',
            'model_strength' => 0.75,
            'historical_success_rate' => 0.85,
            'give_back_rate' => 0.1,
            'proven' => true,
            'optional_provider_ready' => true,
        ], $overrides);
    }

    private function frontierPool(array $overrides = []): array
    {
        return array_merge([
            'pool_id' => 'frontier-pool',
            'cost_tier' => 'frontier',
            'model_strength' => 0.95,
            'historical_success_rate' => 0.95,
            'give_back_rate' => 0.02,
            'proven' => true,
            'optional_provider_ready' => true,
        ], $overrides);
    }

    public function test_cheap_first_low_criticality_task_routes_to_cheap_pool(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->cheapPool(), $this->strongPool(), $this->frontierPool()],
            'task_criticality' => 'low',
            'required_context_depth' => 0.2,
        ]);

        $this->assertSame('cheap-pool', $result['route_decision']['pool_id']);
        $this->assertSame('cheap_first', $result['escalation_policy']['mode']);
        $this->assertTrue($result['escalation_policy']['cheap_first']);
    }

    public function test_high_required_context_depth_rejects_pools_with_insufficient_model_strength(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->cheapPool(), $this->frontierPool()],
            'task_criticality' => 'low',
            'required_context_depth' => 0.9,
        ]);

        $this->assertSame('frontier-pool', $result['route_decision']['pool_id']);
        $rejectedIds = array_column($result['rejected_candidates'], 'pool_id');
        $this->assertContains('cheap-pool', $rejectedIds);
        $reasons = array_column($result['rejected_candidates'], 'reason');
        $this->assertContains('insufficient_model_strength_for_required_context_depth', $reasons);
    }

    public function test_critical_irreversible_task_blocks_unproven_pool_without_fallback(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->cheapPool(['proven' => false])],
            'task_criticality' => 'critical',
            'task_irreversible' => true,
            'human_independent_atlas_native_fallback_available' => false,
        ]);

        $this->assertNull($result['route_decision']);
        $reasons = array_column($result['rejected_candidates'], 'reason');
        $this->assertContains('unproven_pool_blocked_for_critical_irreversible_task_without_fallback', $reasons);
        $this->assertSame('no_safe_fallback_available', $result['fallback_route']['reason']);
    }

    public function test_critical_task_allows_unproven_pool_when_atlas_native_fallback_available(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->cheapPool(['proven' => false])],
            'task_criticality' => 'critical',
            'task_irreversible' => true,
            'human_independent_atlas_native_fallback_available' => true,
        ]);

        $this->assertSame('cheap-pool', $result['route_decision']['pool_id']);
        $this->assertSame('frontier_required', $result['escalation_policy']['mode']);
    }

    public function test_route_decision_rejected_candidates_fallback_route_and_escalation_policy_are_present(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->cheapPool(), $this->strongPool()],
            'task_criticality' => 'medium',
        ]);

        $this->assertArrayHasKey('route_decision', $result);
        $this->assertArrayHasKey('rejected_candidates', $result);
        $this->assertArrayHasKey('fallback_route', $result);
        $this->assertArrayHasKey('escalation_policy', $result);
        $this->assertNotSame($result['route_decision']['pool_id'], $result['fallback_route']['pool_id']);
    }

    public function test_unready_optional_provider_is_rejected(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->frontierPool(['optional_provider_ready' => false]), $this->cheapPool()],
            'task_criticality' => 'low',
        ]);

        $reasons = array_column($result['rejected_candidates'], 'reason');
        $this->assertContains('optional_provider_not_ready', $reasons);
        $this->assertSame('cheap-pool', $result['route_decision']['pool_id']);
    }

    public function test_no_safe_pool_falls_back_to_atlas_native_when_human_independent_available(): void
    {
        $result = $this->router()->route([
            'candidates' => [],
            'task_criticality' => 'low',
            'human_independent_atlas_native_fallback_available' => true,
        ]);

        $this->assertNull($result['route_decision']);
        $this->assertSame('atlas_native_self_construction', $result['fallback_route']['pool_id']);
    }
}
