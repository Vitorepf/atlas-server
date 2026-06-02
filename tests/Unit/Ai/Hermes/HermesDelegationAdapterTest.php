<?php

namespace Tests\Unit\Ai\Hermes;

use App\Models\AiJob;
use App\Services\Ai\Hermes\HermesDelegationAdapter;
use Illuminate\Support\Str;
use Tests\TestCase;

class HermesDelegationAdapterTest extends TestCase
{
    private const BLOCKED = ['delegation', 'clarify', 'memory', 'code_execution', 'send_message'];

    private function adapter(): HermesDelegationAdapter
    {
        return app(HermesDelegationAdapter::class);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function manifestWithDelegation(bool $supported = true): array
    {
        return [
            [
                'id' => 'delegation:supported',
                'capability_class' => 'delegation',
                'capability_key' => 'supported',
                'hermes_token' => 'delegation',
                'supported' => $supported,
                'requires_config' => false,
                'detail' => 'delegate_task tool',
                'source' => 'probe',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $delegation
     */
    private function job(array $delegation = [], ?string $traceId = null): AiJob
    {
        return new AiJob([
            'trace_id' => $traceId ?? (string) Str::uuid(),
            'payload' => [
                'hermes' => [
                    'delegation' => $delegation,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $missionDelegation
     * @param  array<int,array<string,mixed>>  $manifest
     * @param  array<string,mixed>  $jobDelegation
     * @return array<string,mixed>
     */
    private function authorize(
        string $delegationPolicy,
        string $permissionMode,
        array $missionDelegation = [],
        array $manifest = [],
        array $jobDelegation = [],
        ?string $traceId = null,
    ): array {
        $mission = ['mission_id' => 'm', 'mission_hash' => 'mh'];
        if ($missionDelegation !== []) {
            $mission['delegation'] = $missionDelegation;
        }

        return $this->adapter()->authorize(
            $this->job($jobDelegation, $traceId),
            $mission,
            ['command' => ['hermes', 'chat']],
            $delegationPolicy,
            $permissionMode,
            $manifest,
        );
    }

    private function assertConstantGovernanceInvariants(array $receipt): void
    {
        $this->assertSame('atlas.hermes.delegation_adapter_receipt.v1', data_get($receipt, 'schema_version'));
        $this->assertSame('hermes_delegation_adapter', data_get($receipt, 'adapter'));
        $this->assertSame('atlas', data_get($receipt, 'delegation_authority'));
        $this->assertFalse((bool) data_get($receipt, 'hermes_delegation_can_decide'));
        $this->assertTrue((bool) data_get($receipt, 'hermes_delegation_can_delegate'));
        $this->assertTrue((bool) data_get($receipt, 'child_runs_evidence_tracked'));
        $this->assertTrue((bool) data_get($receipt, 'subagent_stop_hook_required'));
        $this->assertSame(self::BLOCKED, data_get($receipt, 'blocked_child_toolsets'));
        $this->assertNotEmpty(data_get($receipt, 'receipt_hash'));
    }

    public function test_off_by_default_when_policy_absent_or_off(): void
    {
        $receipt = $this->authorize('off', 'write', manifest: $this->manifestWithDelegation());

        $this->assertFalse((bool) data_get($receipt, 'delegation_enabled'));
        $this->assertSame('delegation_disabled_by_policy', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'toolset_mutation.delegation_appended'));
        $this->assertSame('delegation_policy_not_atlas_adapter', data_get($receipt, 'blocked_reason'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_capability_absent_yields_capability_unavailable(): void
    {
        $receipt = $this->authorize('atlas_adapter', 'write', manifest: $this->manifestWithDelegation(supported: false));

        $this->assertFalse((bool) data_get($receipt, 'delegation_enabled'));
        $this->assertFalse((bool) data_get($receipt, 'capability_present'));
        $this->assertSame('capability_unavailable', data_get($receipt, 'status'));
        $this->assertSame('delegation_capability_unavailable', data_get($receipt, 'blocked_reason'));
        $this->assertFalse((bool) data_get($receipt, 'toolset_mutation.delegation_appended'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_empty_manifest_is_fail_closed(): void
    {
        $receipt = $this->authorize('atlas_adapter', 'write', manifest: []);

        $this->assertFalse((bool) data_get($receipt, 'capability_present'));
        $this->assertSame('capability_unavailable', data_get($receipt, 'status'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_read_mode_blocks_delegation(): void
    {
        $receipt = $this->authorize('atlas_adapter', 'read', manifest: $this->manifestWithDelegation());

        $this->assertFalse((bool) data_get($receipt, 'delegation_enabled'));
        $this->assertSame('delegation_blocked', data_get($receipt, 'status'));
        $this->assertSame('permission_mode_read', data_get($receipt, 'blocked_reason'));
        $this->assertSame('read', data_get($receipt, 'permission_mode'));
        $this->assertFalse((bool) data_get($receipt, 'toolset_mutation.delegation_appended'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_missing_trace_blocks_delegation(): void
    {
        $job = new AiJob([
            'payload' => ['hermes' => ['delegation' => []]],
        ]);

        $receipt = $this->adapter()->authorize(
            $job,
            ['mission_id' => 'm', 'mission_hash' => 'mh'],
            ['command' => ['hermes']],
            'atlas_adapter',
            'write',
            $this->manifestWithDelegation(),
        );

        $this->assertFalse((bool) data_get($receipt, 'delegation_enabled'));
        $this->assertSame('delegation_blocked', data_get($receipt, 'status'));
        $this->assertSame('atls_trace_missing', data_get($receipt, 'blocked_reason'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_enables_with_caps_in_write_mode(): void
    {
        $receipt = $this->authorize(
            'atlas_adapter',
            'write',
            missionDelegation: [
                'max_concurrent_children' => 2,
                'max_spawn_depth' => 1,
                'child_timeout_seconds' => 300,
                'max_iterations' => 20,
            ],
            manifest: $this->manifestWithDelegation(),
        );

        $this->assertTrue((bool) data_get($receipt, 'delegation_enabled'));
        $this->assertSame('delegation_authorized_flat', data_get($receipt, 'status'));
        $this->assertNull(data_get($receipt, 'blocked_reason'));

        $this->assertSame(2, data_get($receipt, 'config_plan.delegation.max_concurrent_children'));
        $this->assertSame(1, data_get($receipt, 'config_plan.delegation.max_spawn_depth'));
        $this->assertFalse((bool) data_get($receipt, 'config_plan.delegation.orchestrator_enabled'));
        $this->assertSame(300, data_get($receipt, 'config_plan.delegation.child_timeout_seconds'));
        $this->assertSame(20, data_get($receipt, 'config_plan.delegation.max_iterations'));
        $this->assertSame('atlas.hermes.delegation_config_plan.v1', data_get($receipt, 'config_plan.schema_version'));

        $this->assertTrue((bool) data_get($receipt, 'toolset_mutation.delegation_appended'));
        $this->assertContains('delegation', data_get($receipt, 'toolset_mutation.after'));
        $this->assertSame([], data_get($receipt, 'clamped'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_leaf_toolsets_always_blocked(): void
    {
        $receipt = $this->authorize(
            'atlas_adapter',
            'write',
            missionDelegation: [
                'max_concurrent_children' => 2,
                'child_toolsets' => ['filesystem', 'memory', 'code_execution', 'http', 'delegation', 'clarify', 'send_message'],
            ],
            manifest: $this->manifestWithDelegation(),
        );

        $allowed = data_get($receipt, 'allowed_child_toolsets');
        $this->assertContains('filesystem', $allowed);
        $this->assertContains('http', $allowed);
        $this->assertNotContains('memory', $allowed);
        $this->assertNotContains('code_execution', $allowed);
        $this->assertNotContains('delegation', $allowed);
        $this->assertNotContains('clarify', $allowed);
        $this->assertNotContains('send_message', $allowed);

        $this->assertSame(self::BLOCKED, data_get($receipt, 'blocked_child_toolsets'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_depth_gt_1_without_orchestrator_clamps_to_flat(): void
    {
        $receipt = $this->authorize(
            'atlas_adapter',
            'write',
            missionDelegation: ['max_spawn_depth' => 3],
            manifest: $this->manifestWithDelegation(),
        );

        $this->assertTrue((bool) data_get($receipt, 'delegation_enabled'));
        $this->assertSame(1, data_get($receipt, 'config_plan.delegation.max_spawn_depth'));
        $this->assertTrue((bool) data_get($receipt, 'depth_gt_1_blocked'));
        $this->assertSame('orchestrator_authority_required_for_depth_gt_1', data_get($receipt, 'blocked_reason'));
        $this->assertSame('delegation_authorized_flat', data_get($receipt, 'status'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_depth_gt_1_with_orchestrator_authority_is_applied(): void
    {
        // Default depth ceiling is 1, raise it for this orchestrated case.
        config(['atlas.ai.providers.hermes_cli.delegation_spawn_depth_ceiling' => 3]);

        $receipt = $this->authorize(
            'atlas_adapter',
            'danger',
            missionDelegation: ['max_spawn_depth' => 2, 'orchestrator_authorized' => true],
            manifest: $this->manifestWithDelegation(),
            jobDelegation: ['orchestrator_authorized' => true],
        );

        $this->assertTrue((bool) data_get($receipt, 'delegation_enabled'));
        $this->assertTrue((bool) data_get($receipt, 'orchestrator_authorized'));
        $this->assertSame(2, data_get($receipt, 'config_plan.delegation.max_spawn_depth'));
        $this->assertTrue((bool) data_get($receipt, 'config_plan.delegation.orchestrator_enabled'));
        $this->assertFalse((bool) data_get($receipt, 'depth_gt_1_blocked'));
        $this->assertSame('delegation_authorized_orchestrated', data_get($receipt, 'status'));
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_orchestrator_flag_only_on_mission_does_not_unlock_depth(): void
    {
        config(['atlas.ai.providers.hermes_cli.delegation_spawn_depth_ceiling' => 3]);

        $receipt = $this->authorize(
            'atlas_adapter',
            'write',
            missionDelegation: ['max_spawn_depth' => 2, 'orchestrator_authorized' => true],
            manifest: $this->manifestWithDelegation(),
            // job payload does NOT carry the flag -> both must be true
        );

        $this->assertFalse((bool) data_get($receipt, 'orchestrator_authorized'));
        $this->assertSame(1, data_get($receipt, 'config_plan.delegation.max_spawn_depth'));
        $this->assertTrue((bool) data_get($receipt, 'depth_gt_1_blocked'));
        $this->assertSame('delegation_authorized_flat', data_get($receipt, 'status'));
    }

    public function test_caps_are_clamped_to_ceilings(): void
    {
        $receipt = $this->authorize(
            'atlas_adapter',
            'write',
            missionDelegation: [
                'max_concurrent_children' => 10,
                'child_timeout_seconds' => 5000,
                'max_iterations' => 500,
            ],
            manifest: $this->manifestWithDelegation(),
        );

        $this->assertSame(3, data_get($receipt, 'config_plan.delegation.max_concurrent_children'));
        $this->assertSame(600, data_get($receipt, 'config_plan.delegation.child_timeout_seconds'));
        $this->assertSame(50, data_get($receipt, 'config_plan.delegation.max_iterations'));

        $clamped = data_get($receipt, 'clamped');
        $fields = array_column($clamped, 'field');
        $this->assertContains('max_concurrent_children', $fields);
        $this->assertContains('child_timeout_seconds', $fields);
        $this->assertContains('max_iterations', $fields);
        $this->assertCount(3, $clamped);

        foreach ($clamped as $entry) {
            $this->assertArrayHasKey('requested', $entry);
            $this->assertArrayHasKey('applied', $entry);
            $this->assertArrayHasKey('ceiling', $entry);
        }

        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_caps_read_from_job_payload_when_mission_absent(): void
    {
        $receipt = $this->authorize(
            'atlas_adapter',
            'write',
            manifest: $this->manifestWithDelegation(),
            jobDelegation: [
                'max_concurrent_children' => 2,
                'child_timeout_seconds' => 120,
                'max_iterations' => 15,
            ],
        );

        $this->assertSame(2, data_get($receipt, 'config_plan.delegation.max_concurrent_children'));
        $this->assertSame(120, data_get($receipt, 'config_plan.delegation.child_timeout_seconds'));
        $this->assertSame(15, data_get($receipt, 'config_plan.delegation.max_iterations'));
    }

    public function test_receipt_is_sealed_and_deterministic(): void
    {
        $traceId = (string) Str::uuid();
        $args = [
            'atlas_adapter',
            'write',
            ['max_concurrent_children' => 2, 'child_toolsets' => ['filesystem']],
            $this->manifestWithDelegation(),
            [],
            $traceId,
        ];

        $first = $this->authorize(...$args);
        $second = $this->authorize(...$args);

        $this->assertNotEmpty(data_get($first, 'receipt_hash'));
        $this->assertSame(data_get($first, 'receipt_hash'), data_get($second, 'receipt_hash'));
    }

    public function test_no_raw_child_goals_or_prompts_stored(): void
    {
        $secretGoal = 'EXFILTRATE_SECRET_GOAL_sk-9999';
        $job = new AiJob([
            'trace_id' => (string) Str::uuid(),
            'payload' => [
                'hermes' => [
                    'delegation' => [
                        'max_concurrent_children' => 2,
                        'child_toolsets' => ['filesystem'],
                        'goal' => $secretGoal,
                        'tasks' => [['goal' => $secretGoal, 'context' => 'secret context']],
                    ],
                ],
            ],
        ]);

        $receipt = $this->adapter()->authorize(
            $job,
            ['mission_id' => 'm', 'mission_hash' => 'mh'],
            ['command' => ['hermes']],
            'atlas_adapter',
            'write',
            $this->manifestWithDelegation(),
        );

        $json = json_encode($receipt);
        $this->assertIsString($json);
        $this->assertStringNotContainsString($secretGoal, $json);
        $this->assertStringNotContainsString('secret context', $json);
        $this->assertConstantGovernanceInvariants($receipt);
    }

    public function test_governance_invariants_hold_on_every_path(): void
    {
        $paths = [
            $this->authorize('off', 'write', manifest: $this->manifestWithDelegation()),
            $this->authorize('atlas_adapter', 'write', manifest: $this->manifestWithDelegation(supported: false)),
            $this->authorize('atlas_adapter', 'read', manifest: $this->manifestWithDelegation()),
            $this->authorize('atlas_adapter', 'write', missionDelegation: ['max_concurrent_children' => 2], manifest: $this->manifestWithDelegation()),
        ];

        foreach ($paths as $receipt) {
            $this->assertConstantGovernanceInvariants($receipt);
        }
    }
}
