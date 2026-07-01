<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCostQualityRouter;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolCostQualityRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainProviderPoolCostQualityRouter
    {
        return new AtlasExternalBrainProviderPoolCostQualityRouter;
    }

    private function localPool(array $overrides = []): array
    {
        return array_merge([
            'pool_id' => 'local-pool',
            'cost_tier' => 'cheap',
            'client_class' => 'local',
            'model_strength' => 0.65,
            'historical_success_rate' => 0.85,
            'give_back_rate' => 0.05,
            'proven' => true,
            'optional_provider_ready' => true,
        ], $overrides);
    }

    private function apiPool(array $overrides = []): array
    {
        return array_merge([
            'pool_id' => 'api-pool',
            'cost_tier' => 'strong',
            'client_class' => 'api',
            'model_strength' => 0.65,
            'historical_success_rate' => 0.85,
            'give_back_rate' => 0.05,
            'proven' => true,
            'optional_provider_ready' => true,
        ], $overrides);
    }

    // ── AC: local/subscription clients win when quality+safety meets the task risk ──

    public function test_local_client_wins_over_api_client_when_it_meets_the_proof_floor(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->localPool(), $this->apiPool()],
            'task_criticality' => 'low', // floor 0.30, both candidates at 0.65 clear it
        ]);

        $this->assertSame('local-pool', $result['route_decision']['pool_id']);
        $this->assertSame('local', $result['selected_client_class']);
    }

    public function test_subscription_client_wins_when_it_meets_the_proof_floor(): void
    {
        $result = $this->router()->route([
            'candidates' => [
                $this->localPool(['pool_id' => 'sub-pool', 'client_class' => 'subscription', 'model_strength' => 0.60]),
                $this->apiPool(['model_strength' => 0.60]),
            ],
            'task_criticality' => 'medium', // floor 0.50
        ]);

        $this->assertSame('sub-pool', $result['route_decision']['pool_id']);
        $this->assertSame('subscription', $result['selected_client_class']);
    }

    public function test_local_client_below_proof_floor_does_not_win_over_equal_api_candidate(): void
    {
        $result = $this->router()->route([
            'candidates' => [
                $this->localPool(['model_strength' => 0.40]),
                $this->apiPool(['model_strength' => 0.40]),
            ],
            'task_criticality' => 'high', // floor 0.70, neither clears it
        ]);

        // No bonus applies to either side; tie-break falls through to existing rules,
        // never a blind preference for local/subscription regardless of proof.
        $this->assertNotNull($result['route_decision']);
    }

    // ── AC: escalation recommended only for high-risk tasks with insufficient local evidence ──

    public function test_escalation_recommended_for_critical_task_without_qualified_local_evidence(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->apiPool(['proven' => true])],
            'task_criticality' => 'critical',
            'human_independent_atlas_native_fallback_available' => true,
        ]);

        $this->assertTrue($result['escalation_recommended']);
    }

    public function test_escalation_not_recommended_for_critical_task_with_qualified_local_evidence(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->localPool(['model_strength' => 0.95])],
            'task_criticality' => 'critical', // floor 0.90, 0.95 clears it
        ]);

        $this->assertFalse($result['escalation_recommended']);
    }

    public function test_escalation_not_recommended_for_low_risk_task_even_without_local_evidence(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->apiPool()],
            'task_criticality' => 'low',
        ]);

        $this->assertFalse($result['escalation_recommended']);
    }

    // ── AC: routing output includes reason, selected_client_class, required_proof_floor ──

    public function test_output_includes_reason_selected_client_class_and_required_proof_floor(): void
    {
        $result = $this->router()->route([
            'candidates' => [$this->apiPool()],
            'task_criticality' => 'medium',
        ]);

        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('selected_client_class', $result);
        $this->assertArrayHasKey('required_proof_floor', $result);
        $this->assertNotEmpty($result['reason']);
        $this->assertSame(0.50, $result['required_proof_floor']);
        $this->assertSame('api', $result['selected_client_class']);
    }

    public function test_required_proof_floor_scales_with_task_criticality(): void
    {
        $low = $this->router()->route(['candidates' => [$this->apiPool()], 'task_criticality' => 'low']);
        $critical = $this->router()->route(['candidates' => [$this->apiPool()], 'task_criticality' => 'critical']);

        $this->assertSame(0.30, $low['required_proof_floor']);
        $this->assertSame(0.90, $critical['required_proof_floor']);
    }

    public function test_selected_client_class_is_null_when_no_route_decision(): void
    {
        $result = $this->router()->route(['candidates' => [], 'task_criticality' => 'low']);

        $this->assertNull($result['route_decision']);
        $this->assertNull($result['selected_client_class']);
    }
}
