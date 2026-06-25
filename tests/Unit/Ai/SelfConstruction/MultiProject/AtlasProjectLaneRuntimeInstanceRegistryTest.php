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
}
