<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientGovernanceRunner;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientGovernanceRunnerTest extends TestCase
{
    private AtlasExternalBrainLocalClientGovernanceRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new AtlasExternalBrainLocalClientGovernanceRunner;
    }

    public function test_healthy_scenario_with_atlas_native_fallback_returns_continue(): void
    {
        // Local client available, subscription reliable, Atlas-native fallback present.
        $result = $this->runner->run([
            'local_client_available' => true,
            'subscription_reliable' => true,
            'atlas_native_fallback_capacity_available' => true,
            'manual_muscle_available' => true,
        ]);

        $this->assertSame('continue', $result['verdict']);
        $this->assertTrue($result['acceleration_allowed']);
        $this->assertFalse($result['steady_state_dependency_allowed']);
        $this->assertArrayHasKey('fallback', $result);
        $this->assertArrayHasKey('recovery', $result);
        $this->assertArrayHasKey('cost', $result);
        $this->assertArrayHasKey('fragility', $result);
    }

    public function test_local_only_dependency_without_fallback_returns_fallback_pause(): void
    {
        // Local client available but NO Atlas-native fallback.
        $result = $this->runner->run([
            'local_client_available' => true,
            'subscription_reliable' => true,
            'atlas_native_fallback_capacity_available' => false,
            'manual_muscle_available' => false,
        ]);

        $this->assertSame('fallback_pause', $result['verdict']);
        $this->assertTrue($result['acceleration_allowed'], 'acceleration still allowed for short tasks');
        $this->assertContains('steady_state_depends_on_local_client_no_independent_fallback', $result['blockers']);
        $this->assertContains('atlas_native_fallback_capacity', $result['fallback']['missing_atlas_native_fallback_capabilities']);
        $this->assertContains('manual_muscle_availability', $result['fallback']['missing_atlas_native_fallback_capabilities']);
    }

    public function test_run_includes_all_four_sections(): void
    {
        $result = $this->runner->run([]);

        $this->assertArrayHasKey('fallback', $result);
        $this->assertArrayHasKey('recovery', $result);
        $this->assertArrayHasKey('cost', $result);
        $this->assertArrayHasKey('fragility', $result);

        // Fallback
        $this->assertArrayHasKey('decision', $result['fallback']);
        $this->assertArrayHasKey('atlas_native_fallback_available', $result['fallback']);
        $this->assertArrayHasKey('missing_atlas_native_fallback_capabilities', $result['fallback']);

        // Recovery
        $this->assertArrayHasKey('decision', $result['recovery']);
        $this->assertArrayHasKey('next_safe_action', $result['recovery']);
        $this->assertArrayHasKey('no_loss_recovery_plan', $result['recovery']);

        // Cost
        $this->assertArrayHasKey('cost_guard_status', $result['cost']);
        $this->assertArrayHasKey('safe_for_24_7', $result['cost']);
        $this->assertArrayHasKey('client_class', $result['cost']);
        $this->assertArrayHasKey('fallback_recommendation', $result['cost']);

        // Fragility
        $this->assertArrayHasKey('safe_for_24_7', $result['fragility']);
        $this->assertArrayHasKey('recommendation', $result['fragility']);
        $this->assertArrayHasKey('risk_reason_count', $result['fragility']);
    }

    public function test_run_is_deterministic(): void
    {
        $input = [
            'local_client_available' => true,
            'subscription_reliable' => false,
        ];

        $a = $this->runner->run($input);
        $b = $this->runner->run($input);

        $this->assertSame($a['verdict'], $b['verdict']);
        $this->assertSame($a['blockers'], $b['blockers']);
    }

    public function test_steady_state_dependency_allowed_is_always_false(): void
    {
        $result = $this->runner->run([]);

        $this->assertFalse($result['steady_state_dependency_allowed'],
            'steady_state_dependency_allowed must always be false — local clients never become steady-state');
    }

    public function test_quit_always_false(): void
    {
        $result = $this->runner->run([]);

        $this->assertFalse($result['mutates_queue']);
    }
}
