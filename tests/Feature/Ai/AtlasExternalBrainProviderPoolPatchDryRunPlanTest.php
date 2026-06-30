<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolPatchDryRunPlan;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolPatchDryRunPlanTest extends TestCase
{
    private AtlasExternalBrainProviderPoolPatchDryRunPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = new AtlasExternalBrainProviderPoolPatchDryRunPlan;
    }

    private function fullEvidence(array $overrides = []): array
    {
        return array_merge([
            'patch_output_contract' => true,
            'scope_guard_contract' => true,
            'no_secret_contract' => true,
            'no_git_contract' => true,
            'no_provider_required_for_steady_state' => true,
        ], $overrides);
    }

    public function test_plan_contains_all_required_elements(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence()]);

        $this->assertSame(AtlasExternalBrainProviderPoolPatchDryRunPlan::SCHEMA, $result['schema']);
        foreach ([
            'fixture_task',
            'allowed_files_boundary',
            'expected_patch_shape',
            'forbidden_edit_examples',
            'test_command',
            'timeout',
            'fallback_replay',
        ] as $key) {
            $this->assertArrayHasKey($key, $result['plan']);
            $this->assertNotEmpty($result['plan'][$key]);
        }
    }

    public function test_safety_flags_are_all_false_and_plan_only(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence()]);

        $this->assertTrue($result['plan_only']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
    }

    public function test_all_evidence_contracts_present_is_ready_for_sandboxed_trial(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence()]);

        $this->assertSame('ready_for_sandboxed_trial', $result['status']);
        $this->assertSame([], $result['missing_evidence']);
    }

    public function test_missing_one_contract_blocks_ready_status(): void
    {
        $result = $this->plan->plan(['evidence' => $this->fullEvidence(['no_git_contract' => false])]);

        $this->assertSame('evidence_incomplete', $result['status']);
        $this->assertSame(['no_git_contract'], $result['missing_evidence']);
    }

    public function test_no_evidence_supplied_lists_all_five_contracts_missing(): void
    {
        $result = $this->plan->plan([]);

        $this->assertSame('evidence_incomplete', $result['status']);
        $this->assertSame(AtlasExternalBrainProviderPoolPatchDryRunPlan::REQUIRED_EVIDENCE, $result['missing_evidence']);
    }

    public function test_required_evidence_covers_five_named_contracts(): void
    {
        $this->assertSame([
            'patch_output_contract',
            'scope_guard_contract',
            'no_secret_contract',
            'no_git_contract',
            'no_provider_required_for_steady_state',
        ], AtlasExternalBrainProviderPoolPatchDryRunPlan::REQUIRED_EVIDENCE);
    }
}
