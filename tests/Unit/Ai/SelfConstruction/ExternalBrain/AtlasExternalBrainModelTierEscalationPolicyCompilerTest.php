<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelTierEscalationPolicyCompiler;
use Tests\TestCase;

final class AtlasExternalBrainModelTierEscalationPolicyCompilerTest extends TestCase
{
    private function task(array $overrides = []): array
    {
        return array_merge([
            'risk_level' => 'low',
            'ambiguity' => 'low',
            'blast_radius' => 'low',
            'historical_success_rate' => 0.9,
            'give_back_count' => 0,
            'acceptance_strength' => 'strong',
            'implementation_risk' => 'low',
        ], $overrides);
    }

    private function compiler(): AtlasExternalBrainModelTierEscalationPolicyCompiler
    {
        return new AtlasExternalBrainModelTierEscalationPolicyCompiler;
    }

    public function test_low_risk_deterministic_task_with_high_historical_success_routes_to_small_with_scaffold(): void
    {
        $result = $this->compiler()->compile($this->task());

        $this->assertSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_SMALL_WITH_SCAFFOLD, $result['selected_tier']);
        $this->assertNotEmpty($result['scaffold_requirements']);
    }

    public function test_high_ambiguity_routes_to_frontier_review(): void
    {
        $result = $this->compiler()->compile($this->task(['ambiguity' => 'high']));

        $this->assertSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_FRONTIER_REVIEW, $result['selected_tier']);
        $this->assertStringContainsString('ambiguity', $result['escalation_reason']);
    }

    public function test_high_blast_radius_routes_to_frontier_review(): void
    {
        $result = $this->compiler()->compile($this->task(['blast_radius' => 'high']));

        $this->assertSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_FRONTIER_REVIEW, $result['selected_tier']);
        $this->assertStringContainsString('blast_radius', $result['escalation_reason']);
    }

    public function test_repeated_give_back_routes_to_frontier_review(): void
    {
        $result = $this->compiler()->compile($this->task(['give_back_count' => 3]));

        $this->assertSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_FRONTIER_REVIEW, $result['selected_tier']);
        $this->assertStringContainsString('give_back', $result['escalation_reason']);
    }

    public function test_single_give_back_does_not_yet_trigger_frontier_escalation(): void
    {
        $result = $this->compiler()->compile($this->task(['give_back_count' => 1]));

        $this->assertNotSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_FRONTIER_REVIEW, $result['selected_tier']);
    }

    public function test_weak_acceptance_with_low_implementation_risk_routes_to_improve_spec_not_frontier(): void
    {
        $result = $this->compiler()->compile($this->task([
            'acceptance_strength' => 'weak',
            'implementation_risk' => 'low',
        ]));

        $this->assertSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_IMPROVE_SPEC, $result['selected_tier']);
        $this->assertNotSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_FRONTIER_REVIEW, $result['selected_tier']);
    }

    public function test_weak_acceptance_takes_priority_over_high_ambiguity_when_implementation_risk_is_low(): void
    {
        // Weak acceptance + low implementation risk must route to spec repair even if ambiguity also
        // looks high — the deficiency is the spec, not the task's inherent difficulty.
        $result = $this->compiler()->compile($this->task([
            'acceptance_strength' => 'weak',
            'implementation_risk' => 'low',
            'ambiguity' => 'high',
        ]));

        $this->assertSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_IMPROVE_SPEC, $result['selected_tier']);
    }

    public function test_weak_acceptance_with_high_implementation_risk_does_not_route_to_improve_spec(): void
    {
        $result = $this->compiler()->compile($this->task([
            'acceptance_strength' => 'weak',
            'implementation_risk' => 'high',
            'ambiguity' => 'high',
        ]));

        $this->assertNotSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_IMPROVE_SPEC, $result['selected_tier']);
    }

    public function test_output_includes_all_four_required_fields(): void
    {
        $result = $this->compiler()->compile($this->task());

        $this->assertArrayHasKey('selected_tier', $result);
        $this->assertArrayHasKey('scaffold_requirements', $result);
        $this->assertArrayHasKey('escalation_reason', $result);
        $this->assertArrayHasKey('cost_guardrail', $result);
    }

    public function test_no_strong_signal_falls_back_to_standard_review(): void
    {
        $result = $this->compiler()->compile($this->task(['historical_success_rate' => 0.4]));

        $this->assertSame(AtlasExternalBrainModelTierEscalationPolicyCompiler::TIER_STANDARD_REVIEW, $result['selected_tier']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $compiler = $this->compiler();
        $task = $this->task();

        $this->assertSame($compiler->compile($task), $compiler->compile($task));
    }
}
