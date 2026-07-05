<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceRegistry;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSchedulerManifest;
use Tests\TestCase;

final class AtlasProjectLaneRuntimeInstanceRegistryTest extends TestCase
{
    private function manifest(): array
    {
        return (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();
    }

    private function lane(array $overrides = []): array
    {
        return array_replace([
            'lane_id' => 'lane-x',
            'project_id' => 'demo',
            'lane_type' => 'external_project',
            'queue_namespace' => 'demo.scope',
            'execution_topology' => 'shared_local_main_with_scope_lock',
            'allowed_roots' => ['projects/demo/src'],
            'forbidden_roots' => ['projects/demo/secrets'],
            'lane_boundary_root' => 'projects/demo',
            'steady_state_dependencies' => [],
            'verification_hooks' => ['phpunit', 'mutop'],
            'release_hooks' => ['atlas_merge_governor'],
            'knowledge_sync_hooks' => ['docs_health', 'kb_sync'],
            'isolation_evidence_refs' => ['receipts/demo/lane-x.jsonl'],
        ], $overrides);
    }

    public function test_valid_external_lane_becomes_isolated_runtime_instance(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(), $this->manifest());

        $this->assertSame(AtlasProjectLaneRuntimeInstanceRegistry::SCHEMA, $verdict['schema_version']);
        $this->assertSame('ok', $verdict['status']);
        $this->assertCount(1, $verdict['instances']);
        $inst = $verdict['instances'][0];
        $this->assertSame('lane-x', $inst['lane_id']);
        $this->assertSame('demo', $inst['project_id']);
        $this->assertSame('demo.scope', $inst['queue_namespace']);
        $this->assertStringContainsString('atlas:self-construction:runtime-daemon', $inst['daemon_tick_command']);
        $this->assertSame('shared_local_main_with_scope_lock', $inst['execution_topology']);
        $this->assertNotEmpty($inst['scheduler_policy']['cadence_seconds']);
        $this->assertSame(['receipts/demo/lane-x.jsonl'], $inst['isolation_evidence_refs']);
        $this->assertSame(['docs_health', 'kb_sync'], $inst['knowledge_sync_hooks']);
        $this->assertSame(['phpunit', 'mutop'], $inst['verification_hooks']);
        $this->assertSame(['atlas_merge_governor'], $inst['release_hooks']);
    }

    public function test_atlas_internal_lane_also_supported(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['lane_id' => 'lane-atlas', 'project_id' => 'atlas', 'lane_type' => 'atlas_internal', 'lane_boundary_root' => 'app', 'allowed_roots' => ['app/Services']]),
            $this->manifest(),
        );

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('atlas_internal', $verdict['instances'][0]['lane_type']);
    }

    public function test_missing_queue_namespace_is_rejected(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(['queue_namespace' => '']), $this->manifest());
        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('lane-x:queue_namespace_missing', $verdict['blockers']);
    }

    public function test_missing_allowed_roots_is_rejected(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(['allowed_roots' => []]), $this->manifest());
        $this->assertContains('lane-x:allowed_roots_missing', $verdict['blockers']);
    }

    public function test_allowed_root_outside_lane_boundary_is_rejected(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['allowed_roots' => ['projects/demo/src', '/etc/passwd']]),
            $this->manifest(),
        );
        $this->assertContains('lane-x:allowed_root_outside_lane_boundary:/etc/passwd', $verdict['blockers']);
    }

    public function test_non_shared_local_main_topology_is_rejected(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['execution_topology' => 'isolated_worktree']),
            $this->manifest(),
        );
        $this->assertContains('lane-x:execution_topology_unexpected:isolated_worktree', $verdict['blockers']);
    }

    public function test_steady_state_external_dependency_is_refused(): void
    {
        foreach (['operator', 'human', 'external_provider', 'claude_code', 'codex', 'cursor', 'git', 'network', 'unrestricted_shell'] as $dep) {
            $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
                $this->lane(['steady_state_dependencies' => [$dep]]),
                $this->manifest(),
            );
            $this->assertContains('lane-x:steady_state_dependency_refused:'.$dep, $verdict['blockers'], "dependency {$dep} must be refused");
        }
    }

    public function test_missing_scheduler_manifest_is_rejected_at_registry_level(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(), ['tick_command' => '']);
        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('scheduler_manifest_schema_mismatch', $verdict['blockers']);
        $this->assertContains('scheduler_manifest_tick_command_missing', $verdict['blockers']);
    }

    public function test_batch_lanes_input_returns_one_instance_per_lane(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build([
            'lanes' => [
                $this->lane(['lane_id' => 'lane-1', 'project_id' => 'a', 'queue_namespace' => 'a.q', 'allowed_roots' => ['projects/a/src'], 'lane_boundary_root' => 'projects/a']),
                $this->lane(['lane_id' => 'lane-2', 'project_id' => 'b', 'queue_namespace' => 'b.q', 'allowed_roots' => ['projects/b/src'], 'lane_boundary_root' => 'projects/b']),
            ],
        ], $this->manifest());

        $this->assertSame('ok', $verdict['status']);
        $this->assertCount(2, $verdict['instances']);
        $ids = array_column($verdict['instances'], 'lane_id');
        $this->assertSame(['lane-1', 'lane-2'], $ids);
    }

    public function test_registry_hash_is_deterministic_for_identical_input(): void
    {
        $a = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(), $this->manifest());
        $b = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(), $this->manifest());

        $this->assertSame($a['registry_hash'], $b['registry_hash']);
    }

    public function test_duplicate_queue_namespace_across_lanes_is_rejected(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build([
            'lanes' => [
                $this->lane(['lane_id' => 'lane-1', 'project_id' => 'a', 'queue_namespace' => 'shared.ns', 'allowed_roots' => ['projects/a/src'], 'lane_boundary_root' => 'projects/a']),
                $this->lane(['lane_id' => 'lane-2', 'project_id' => 'b', 'queue_namespace' => 'shared.ns', 'allowed_roots' => ['projects/b/src'], 'lane_boundary_root' => 'projects/b']),
            ],
        ], $this->manifest());

        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('duplicate_queue_namespace:shared.ns', $verdict['blockers']);
    }

    public function test_forbidden_roots_that_overlap_allowed_roots_are_rejected(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['allowed_roots' => ['projects/demo/src'], 'forbidden_roots' => ['projects/demo/src/sensitive']]),
            $this->manifest(),
        );

        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('lane-x:forbidden_root_overlaps_allowed:projects/demo/src/sensitive', $verdict['blockers']);
    }

    public function test_runtime_owner_and_steady_state_owner_must_be_atlas_native_or_atlas_server(): void
    {
        foreach (['operator', 'human', 'claude_code', 'codex', 'cursor', 'external_provider'] as $forbidden) {
            $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
                $this->lane(['runtime_owner' => $forbidden]),
                $this->manifest(),
            );
            $this->assertSame('rejected', $verdict['status'], "runtime_owner=$forbidden must be rejected");
            $this->assertContains('lane-x:runtime_owner_not_atlas:'.$forbidden, $verdict['blockers']);
        }

        $verdict2 = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['steady_state_owner' => 'operator']),
            $this->manifest(),
        );
        $this->assertSame('rejected', $verdict2['status']);
        $this->assertContains('lane-x:steady_state_owner_not_atlas:operator', $verdict2['blockers']);
    }

    public function test_registry_hash_is_stable_when_lane_input_key_order_differs(): void
    {
        $laneA = $this->lane();
        $laneB = array_merge(
            ['allowed_roots' => $laneA['allowed_roots'], 'lane_id' => $laneA['lane_id']],
            array_diff_key($laneA, ['allowed_roots' => null, 'lane_id' => null]),
        );

        $hashA = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($laneA, $this->manifest())['registry_hash'];
        $hashB = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($laneB, $this->manifest())['registry_hash'];

        $this->assertSame($hashA, $hashB, 'registry_hash must be stable regardless of input key order');
    }

    // ── AC: invalid lane type or missing shared_local_main_with_scope_lock blocks instance creation ──

    public function test_invalid_lane_type_blocks_instance_creation(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['lane_type' => 'invalid_type']),
            $this->manifest(),
        );

        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('lane-x:lane_type_invalid:invalid_type', $verdict['blockers']);
    }

    public function test_missing_shared_local_main_with_scope_lock_blocks_instance_creation(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['execution_topology' => 'isolated_worktree']),
            $this->manifest(),
        );

        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('lane-x:execution_topology_unexpected:isolated_worktree', $verdict['blockers']);
    }

    // ── AC: human, operator or provider steady-state dependency blocks the instance ──

    public function test_human_steady_state_dependency_blocks_instance(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['steady_state_dependencies' => ['human']]),
            $this->manifest(),
        );

        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('lane-x:steady_state_dependency_refused:human', $verdict['blockers']);
    }

    public function test_operator_steady_state_dependency_blocks_instance(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['steady_state_dependencies' => ['operator']]),
            $this->manifest(),
        );

        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('lane-x:steady_state_dependency_refused:operator', $verdict['blockers']);
    }

    public function test_external_provider_steady_state_blocks_instance(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build(
            $this->lane(['steady_state_dependencies' => ['external_provider']]),
            $this->manifest(),
        );

        $this->assertSame('rejected', $verdict['status']);
        $this->assertContains('lane-x:steady_state_dependency_refused:external_provider', $verdict['blockers']);
    }

    // ── AC: valid lane facts produce an instance with blockers=[] and topology summary ──

    public function test_valid_lane_facts_produce_instance_with_blockers_empty(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(), $this->manifest());

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertCount(1, $verdict['instances']);
    }

    public function test_valid_instance_includes_topology_summary(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceRegistry)->build($this->lane(), $this->manifest());
        $inst = $verdict['instances'][0];

        $this->assertSame('shared_local_main_with_scope_lock', $inst['execution_topology']);
        $this->assertArrayHasKey('allowed_roots', $inst);
        $this->assertArrayHasKey('forbidden_roots', $inst);
        $this->assertArrayHasKey('scheduler_policy', $inst);
        $this->assertArrayHasKey('cadence_seconds', $inst['scheduler_policy']);
    }
}
