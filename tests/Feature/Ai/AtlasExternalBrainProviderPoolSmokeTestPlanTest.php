<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolSmokeTestPlan;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolSmokeTestPlanTest extends TestCase
{
    private AtlasExternalBrainProviderPoolSmokeTestPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = new AtlasExternalBrainProviderPoolSmokeTestPlan;
    }

    private function fullEvidence(array $overrides = []): array
    {
        return array_merge([
            'sdk_proof' => true,
            'entitlement_proof' => true,
            'output_contract_proof' => true,
            'cost_proof' => true,
            'fallback_proof' => true,
        ], $overrides);
    }

    public function test_steps_are_ordered_and_cover_the_seven_required_stages(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence()]);

        $this->assertSame(AtlasExternalBrainProviderPoolSmokeTestPlan::SCHEMA, $result['schema']);
        $ids = array_column($result['steps'], 'id');
        $this->assertSame([
            'preflight',
            'harmless_prompt',
            'patch_generation_dry_run',
            'no_secret_policy_check',
            'cost_boundary_check',
            'timeout_boundary',
            'fallback_replay',
        ], $ids);
        $orders = array_column($result['steps'], 'order');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $orders);
    }

    public function test_every_step_is_non_executing_plan_only(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence()]);

        foreach ($result['steps'] as $step) {
            $this->assertTrue($step['non_executing_plan_only']);
        }
    }

    public function test_top_level_flags_are_all_false(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence()]);

        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
    }

    public function test_all_evidence_present_is_ready_for_optional_routing(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence()]);

        $this->assertSame('ready_for_optional_routing', $result['status']);
        $this->assertSame([], $result['missing_evidence']);
    }

    public function test_missing_one_evidence_field_blocks_ready_status(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence(['cost_proof' => false])]);

        $this->assertSame('evidence_incomplete', $result['status']);
        $this->assertSame(['cost_proof'], $result['missing_evidence']);
    }

    public function test_no_evidence_supplied_lists_all_five_as_missing(): void
    {
        $result = $this->plan->plan([]);

        $this->assertSame('evidence_incomplete', $result['status']);
        $this->assertSame(AtlasExternalBrainProviderPoolSmokeTestPlan::REQUIRED_EVIDENCE, $result['missing_evidence']);
        $this->assertSame(AtlasExternalBrainProviderPoolSmokeTestPlan::REQUIRED_EVIDENCE, $result['required_evidence']);
    }

    public function test_required_evidence_covers_five_named_proofs(): void
    {
        $this->assertSame([
            'sdk_proof',
            'entitlement_proof',
            'output_contract_proof',
            'cost_proof',
            'fallback_proof',
        ], AtlasExternalBrainProviderPoolSmokeTestPlan::REQUIRED_EVIDENCE);
    }
}
