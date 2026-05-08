<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowTransitionPolicy;
use Tests\TestCase;

final class AtlasApAgentWorkflowTransitionPolicyTest extends TestCase
{
    public function test_policy_declares_allowed_transitions_without_execution(): void
    {
        $payload = app(AtlasApAgentWorkflowTransitionPolicy::class)->policy();

        $this->assertSame('atlas.ap_agent_workflow_transition_policy.v1', $payload['schema_version']);
        $this->assertSame('implemented', $payload['status']);
        $this->assertSame('read_only_transition_policy', $payload['mode']);
        $this->assertSame('ap_agent_workflow_transition_policy_only_no_execution', $payload['authority']);
        $this->assertSame('atlas.ap_agent_workflow_registry.v1', $payload['workflow_schema_version']);
        $this->assertSame('ap_agent_documented_work_session', $payload['workflow_id']);
        $this->assertSame(5, $payload['transition_count']);
        $this->assertContains('AP-203', $payload['terminal_steps']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.advances_workflow'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_parallel_flow'));
    }

    public function test_transition_policy_allows_declared_next_steps(): void
    {
        $policy = app(AtlasApAgentWorkflowTransitionPolicy::class);

        $this->assertSame('allowed', $policy->validateTransition('AP-200', 'AP-201')['status']);
        $this->assertSame('allowed', $policy->validateTransition('AP-200', 'AP-202')['status']);
        $this->assertSame('allowed', $policy->validateTransition('AP-202', 'AP-205')['status']);
        $this->assertSame('allowed', $policy->validateTransition('AP-205', 'AP-203')['status']);
    }

    public function test_transition_policy_blocks_skips_terminal_exit_and_unknown_steps(): void
    {
        $policy = app(AtlasApAgentWorkflowTransitionPolicy::class);

        $skip = $policy->validateTransition('AP-202', 'AP-203');
        $terminal = $policy->validateTransition('AP-203', 'AP-200');
        $unknown = $policy->validateTransition('AP-999', 'AP-203');

        $this->assertSame('blocked', $skip['status']);
        $this->assertSame('transition_not_allowed_by_workflow_registry', data_get($skip, 'errors.0.reason'));
        $this->assertSame(['AP-205'], data_get($skip, 'errors.0.allowed_next_steps'));
        $this->assertSame('blocked', $terminal['status']);
        $this->assertSame('from_step_is_terminal', data_get($terminal, 'errors.0.reason'));
        $this->assertSame('blocked', $unknown['status']);
        $this->assertSame('unknown_from_step', data_get($unknown, 'errors.0.reason'));
        $this->assertSame('repair_workflow_transition_before_advancing', $unknown['next_action']);
    }
}
