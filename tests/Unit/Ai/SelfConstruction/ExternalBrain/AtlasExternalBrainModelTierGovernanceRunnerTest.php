<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelTierGovernanceRunner;
use Tests\TestCase;

final class AtlasExternalBrainModelTierGovernanceRunnerTest extends TestCase
{
    private AtlasExternalBrainModelTierGovernanceRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new AtlasExternalBrainModelTierGovernanceRunner;
    }

    public function test_healthy_input_returns_allow_amplification(): void
    {
        // Empty input — no weaknesses, no failing segments, no forced escalation.
        $result = $this->runner->run([]);

        $this->assertSame('allow_amplification', $result['action']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_run_includes_all_four_sections(): void
    {
        $result = $this->runner->run([]);

        $this->assertArrayHasKey('weakness_guard', $result);
        $this->assertArrayHasKey('quality_slo', $result);
        $this->assertArrayHasKey('calibrated_tier', $result);
        $this->assertArrayHasKey('escalation_policy', $result);

        // weakness_guard
        $this->assertArrayHasKey('blocked_until_fixed', $result['weakness_guard']);
        $this->assertArrayHasKey('weakness_count', $result['weakness_guard']);
        $this->assertArrayHasKey('findings', $result['weakness_guard']);

        // quality_slo
        $this->assertArrayHasKey('failing_segment_count', $result['quality_slo']);
        $this->assertArrayHasKey('green_segment_count', $result['quality_slo']);
        $this->assertArrayHasKey('failing_segments', $result['quality_slo']);

        // calibrated_tier
        $this->assertArrayHasKey('tier_stats', $result['calibrated_tier']);
        $this->assertArrayHasKey('routing_recommendations', $result['calibrated_tier']);
        $this->assertArrayHasKey('under_sampled_segments', $result['calibrated_tier']);

        // escalation_policy
        $this->assertArrayHasKey('recommended_tier', $result['escalation_policy']);
        $this->assertArrayHasKey('reason', $result['escalation_policy']);
        $this->assertArrayHasKey('escalation', $result['escalation_policy']);
    }

    public function test_run_is_deterministic(): void
    {
        $a = $this->runner->run([]);
        $b = $this->runner->run([]);

        $this->assertSame($a['action'], $b['action']);
        $this->assertSame($a['blockers'], $b['blockers']);
    }

    public function test_blockers_empty_for_clean_input(): void
    {
        $result = $this->runner->run([]);

        $this->assertSame([], $result['blockers']);
        $this->assertSame('allow_amplification', $result['action']);
    }
}
