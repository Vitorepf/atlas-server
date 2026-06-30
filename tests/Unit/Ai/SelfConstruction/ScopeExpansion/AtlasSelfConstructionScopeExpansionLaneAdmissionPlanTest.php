<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ScopeExpansion;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionLaneAdmissionPlan;
use Tests\TestCase;

final class AtlasSelfConstructionScopeExpansionLaneAdmissionPlanTest extends TestCase
{
    private function candidate(array $overrides = []): array
    {
        return $overrides + ['id' => 'cand-1', 'label' => 'expand authoring', 'lane_type' => 'atlas_internal'];
    }

    private function readyVerdict(): array
    {
        return ['status' => 'ready', 'ready' => true];
    }

    private function laneFacts(array $overrides = []): array
    {
        return array_replace([
            'project_id' => 'atlas-server',
            'lane_type' => 'atlas_internal',
            'queue_namespace' => 'atlas-internal.authoring',
            'allowed_roots' => ['app/Services/Ai/Authoring'],
            'forbidden_roots' => ['app/Services/Ai/AutonomousEvolution/Constitution'],
            'execution_topology' => 'shared_local_main_with_scope_lock',
            'existing_lane_roots' => [],
        ], $overrides);
    }

    public function test_ready_plan_contains_all_required_admission_fields(): void
    {
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts());

        $this->assertSame('atlas.self_construction.scope_expansion_lane_admission_plan.v1', $plan['schema_version']);
        $this->assertSame('ready', $plan['status']);
        foreach (['lane_id', 'project_id', 'lane_type', 'queue_namespace', 'allowed_roots', 'forbidden_roots', 'verification_hooks', 'release_hooks', 'receipt_hooks', 'rollback_hooks', 'knowledge_sync_hooks', 'admission_plan_hash'] as $field) {
            $this->assertArrayHasKey($field, $plan, "missing field: {$field}");
        }
        $this->assertFalse($plan['requires_operator_handoff']);
    }

    public function test_held_when_readiness_not_ready_emits_no_executable_actions(): void
    {
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), ['status' => 'hold'], $this->laneFacts());

        $this->assertSame('held', $plan['status']);
        $this->assertArrayNotHasKey('verification_hooks', $plan);
        $this->assertArrayNotHasKey('release_hooks', $plan);
        $this->assertArrayNotHasKey('receipt_hooks', $plan);
        $this->assertArrayNotHasKey('rollback_hooks', $plan);
    }

    public function test_external_project_lane_is_isolated_and_namespaced(): void
    {
        $facts = $this->laneFacts([
            'project_id' => 'partner-shop',
            'lane_type' => 'external_project',
            'queue_namespace' => 'external.partner-shop.checkout',
            'allowed_roots' => ['vendor-projects/partner-shop/checkout'],
        ]);
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(['lane_type' => 'external_project']), $this->readyVerdict(), $facts);

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('external_project', $plan['lane_type']);
        $this->assertSame('external.partner-shop.checkout', $plan['queue_namespace']);
        $this->assertSame(['vendor-projects/partner-shop/checkout'], $plan['allowed_roots']);
        $this->assertSame('lane:partner-shop:cand-1', $plan['lane_id']);
    }

    public function test_rejects_when_allowed_root_crosses_another_lane(): void
    {
        $facts = $this->laneFacts([
            'existing_lane_roots' => [
                'lane:atlas-server:other' => ['app/Services/Ai/Authoring'],
            ],
        ]);
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), $this->readyVerdict(), $facts);

        $this->assertSame('rejected', $plan['status']);
        $blockerWasFound = false;
        foreach ($plan['blockers'] as $b) {
            if (str_starts_with($b, 'allowed_root_crosses_lane:')) {
                $blockerWasFound = true;
                break;
            }
        }
        $this->assertTrue($blockerWasFound);
    }

    public function test_rejects_when_queue_namespace_missing(): void
    {
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts(['queue_namespace' => '']));

        $this->assertSame('rejected', $plan['status']);
        $this->assertContains('queue_namespace_missing', $plan['blockers']);
    }

    public function test_rejects_when_execution_topology_unexpected(): void
    {
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts(['execution_topology' => 'remote_worker_pool']));

        $this->assertSame('rejected', $plan['status']);
        $this->assertContains('execution_topology_unexpected:remote_worker_pool', $plan['blockers']);
    }

    public function test_admission_plan_hash_is_deterministic(): void
    {
        $a = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts());
        $b = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts());
        $this->assertSame($a['admission_plan_hash'], $b['admission_plan_hash']);
    }

    public function test_rejects_when_quality_floor_not_met(): void
    {
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts(['quality_floor_met' => false]));

        $this->assertSame('rejected', $plan['status']);
        $this->assertContains('quality_floor_not_met', $plan['blockers']);
    }

    public function test_rejects_when_rollback_not_ready(): void
    {
        $plan = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts(['rollback_ready' => false]));

        $this->assertSame('rejected', $plan['status']);
        $this->assertContains('rollback_not_ready', $plan['blockers']);
    }

    public function test_ready_plan_includes_prioritized_lane_output(): void
    {
        $internal = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan($this->candidate(), $this->readyVerdict(), $this->laneFacts());

        $external = (new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan)
            ->plan(
                $this->candidate(['lane_type' => 'external_project']),
                $this->readyVerdict(),
                $this->laneFacts(['lane_type' => 'external_project', 'project_id' => 'ext-proj', 'queue_namespace' => 'ext.ns']),
            );

        $this->assertArrayHasKey('lane_priority', $internal);
        $this->assertArrayHasKey('lane_priority', $external);
        $this->assertLessThan($external['lane_priority'], $internal['lane_priority'], 'atlas_internal must have lower (higher-priority) number than external');
    }

    public function test_plan_source_is_pure(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/ScopeExpansion/AtlasSelfConstructionScopeExpansionLaneAdmissionPlan.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'exec(', 'system(', 'Http::', 'Queue::', 'DB::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "lane admission plan must NOT contain {$forbidden}");
        }
    }
}
