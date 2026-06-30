<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientFallbackPolicy;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientFallbackPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainLocalClientFallbackPolicy
    {
        return new AtlasExternalBrainLocalClientFallbackPolicy;
    }

    public function test_accepts_all_required_facts(): void
    {
        $result = $this->policy()->decide([
            'local_client_available' => true,
            'task_criticality' => 'medium',
            'atlas_native_fallback_capacity_available' => true,
            'manual_muscle_available' => false,
            'subscription_reliable' => true,
        ]);

        foreach ([
            'local_client_available', 'task_criticality', 'atlas_native_fallback_capacity_available',
            'manual_muscle_available', 'subscription_reliable',
        ] as $field) {
            $this->assertArrayHasKey($field, $result, "missing field: {$field}");
        }
    }

    public function test_continues_with_local_client_when_reliable_and_fallback_exists(): void
    {
        $result = $this->policy()->decide([
            'local_client_available' => true,
            'subscription_reliable' => true,
            'atlas_native_fallback_capacity_available' => true,
            'manual_muscle_available' => false,
        ]);

        $this->assertSame('continue_with_local_client', $result['decision']);
        $this->assertFalse($result['mutates_queue']);
    }

    public function test_blocks_local_client_as_sole_path_for_steady_state_autonomy(): void
    {
        $result = $this->policy()->decide([
            'local_client_available' => true,
            'subscription_reliable' => true,
            'atlas_native_fallback_capacity_available' => false,
            'manual_muscle_available' => false,
        ]);

        $this->assertSame('pause_provider_routing', $result['decision']);
        $this->assertSame('local_client_would_be_sole_path_for_steady_state_autonomy', $result['reason']);
        $this->assertContains('atlas_native_fallback_capacity', $result['missing_atlas_native_fallback_capabilities']);
        $this->assertContains('manual_muscle_availability', $result['missing_atlas_native_fallback_capabilities']);
    }

    public function test_falls_back_to_atlas_native_when_local_client_unavailable(): void
    {
        $result = $this->policy()->decide([
            'local_client_available' => false,
            'subscription_reliable' => false,
            'atlas_native_fallback_capacity_available' => true,
            'manual_muscle_available' => true,
        ]);

        $this->assertSame('fallback_to_atlas_native', $result['decision']);
    }

    public function test_falls_back_to_manual_muscle_when_only_manual_muscle_available(): void
    {
        $result = $this->policy()->decide([
            'local_client_available' => false,
            'subscription_reliable' => false,
            'atlas_native_fallback_capacity_available' => false,
            'manual_muscle_available' => true,
        ]);

        $this->assertSame('fallback_to_manual_muscle', $result['decision']);
    }

    public function test_pauses_provider_routing_when_no_path_available(): void
    {
        $result = $this->policy()->decide([
            'local_client_available' => false,
            'subscription_reliable' => false,
            'atlas_native_fallback_capacity_available' => false,
            'manual_muscle_available' => false,
        ]);

        $this->assertSame('pause_provider_routing', $result['decision']);
        $this->assertSame('no_safe_path_available', $result['reason']);
    }

    public function test_unreliable_subscription_does_not_continue_even_if_local_client_available(): void
    {
        $result = $this->policy()->decide([
            'local_client_available' => true,
            'subscription_reliable' => false,
            'atlas_native_fallback_capacity_available' => true,
            'manual_muscle_available' => false,
        ]);

        $this->assertSame('fallback_to_atlas_native', $result['decision']);
    }

    public function test_decision_is_always_one_of_the_four_documented_values(): void
    {
        $allowed = [
            'continue_with_local_client',
            'fallback_to_atlas_native',
            'fallback_to_manual_muscle',
            'pause_provider_routing',
        ];

        foreach ([
            ['local_client_available' => true, 'subscription_reliable' => true, 'atlas_native_fallback_capacity_available' => true, 'manual_muscle_available' => true],
            ['local_client_available' => false, 'subscription_reliable' => false, 'atlas_native_fallback_capacity_available' => false, 'manual_muscle_available' => false],
            ['local_client_available' => true, 'subscription_reliable' => false, 'atlas_native_fallback_capacity_available' => false, 'manual_muscle_available' => true],
        ] as $facts) {
            $result = $this->policy()->decide($facts);
            $this->assertContains($result['decision'], $allowed);
            $this->assertFalse($result['mutates_queue']);
        }
    }
}
