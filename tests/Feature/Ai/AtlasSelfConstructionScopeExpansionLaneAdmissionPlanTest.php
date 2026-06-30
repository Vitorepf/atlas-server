<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionLaneAdmissionPlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionScopeExpansionLaneAdmissionPlanTest extends TestCase
{
    private function planner(): AtlasSelfConstructionScopeExpansionLaneAdmissionPlan
    {
        return new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan;
    }

    private function readyReadiness(): array
    {
        return ['status' => 'ready'];
    }

    private function validLaneFacts(array $overrides = []): array
    {
        return array_merge([
            'project_id'          => 'proj-1',
            'lane_type'           => 'atlas_internal',
            'queue_namespace'     => 'atlas.dev',
            'allowed_roots'       => ['app/Services/Ai'],
            'execution_topology'  => AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::TOPOLOGY_REQUIRED,
            'quality_floor_met'   => true,
            'rollback_ready'      => true,
            'existing_lane_roots' => [],
        ], $overrides);
    }

    // ── AC2: non-ready readiness → held + no lane actions ────────────────────

    public function test_non_ready_readiness_produces_held_status(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'],
            ['status' => 'pending'],
            $this->validLaneFacts(),
        );

        $this->assertSame(AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_HELD, $r['status']);
    }

    public function test_held_plan_has_no_lane_actions(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'],
            ['status' => 'failed'],
            $this->validLaneFacts(),
        );

        $this->assertSame(AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_HELD, $r['status']);
        $this->assertArrayNotHasKey('lane_id', $r);
        $this->assertArrayNotHasKey('verification_hooks', $r);
    }

    // ── AC3: blockers → rejected ──────────────────────────────────────────────

    public function test_missing_project_id_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts(['project_id' => '']),
        );

        $this->assertSame(AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_REJECTED, $r['status']);
        $this->assertContains('project_id_missing', $r['blockers']);
    }

    public function test_missing_queue_namespace_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts(['queue_namespace' => '']),
        );

        $this->assertSame(AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_REJECTED, $r['status']);
        $this->assertContains('queue_namespace_missing', $r['blockers']);
    }

    public function test_empty_allowed_roots_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts(['allowed_roots' => []]),
        );

        $this->assertContains('allowed_roots_empty', $r['blockers']);
    }

    public function test_invalid_lane_type_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts(['lane_type' => 'unknown_type']),
        );

        $blockers = $r['blockers'];
        $this->assertTrue(array_some_contains($blockers, 'lane_type_invalid'), 'Expected lane_type_invalid blocker');
    }

    public function test_wrong_execution_topology_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts(['execution_topology' => 'parallel_branch']),
        );

        $blockers = $r['blockers'];
        $this->assertTrue(array_some_contains($blockers, 'execution_topology_unexpected'), 'Expected execution_topology_unexpected');
    }

    public function test_quality_floor_failure_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts(['quality_floor_met' => false]),
        );

        $this->assertContains('quality_floor_not_met', $r['blockers']);
    }

    public function test_rollback_not_ready_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts(['rollback_ready' => false]),
        );

        $this->assertContains('rollback_not_ready', $r['blockers']);
    }

    public function test_overlapping_allowed_root_is_blocker(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(),
            $this->validLaneFacts([
                'allowed_roots'       => ['app/Services'],
                'existing_lane_roots' => ['other_lane' => ['app/Services/Ai']],
            ]),
        );

        $this->assertSame(AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_REJECTED, $r['status']);
        $this->assertTrue(array_some_contains($r['blockers'], 'allowed_root_crosses_lane'), 'Expected root overlap blocker');
    }

    // ── AC4: valid lane → ready with hooks + requires_operator_handoff=false ─

    public function test_valid_lane_returns_ready_status(): void
    {
        $r = $this->planner()->plan(
            ['id' => 'c1'], $this->readyReadiness(), $this->validLaneFacts(),
        );

        $this->assertSame(AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_READY, $r['status']);
    }

    public function test_valid_lane_has_verification_hook(): void
    {
        $r = $this->planner()->plan(['id' => 'c1'], $this->readyReadiness(), $this->validLaneFacts());

        $this->assertNotEmpty($r['verification_hooks']);
    }

    public function test_valid_lane_has_release_hook(): void
    {
        $r = $this->planner()->plan(['id' => 'c1'], $this->readyReadiness(), $this->validLaneFacts());

        $this->assertNotEmpty($r['release_hooks']);
    }

    public function test_valid_lane_has_rollback_hook(): void
    {
        $r = $this->planner()->plan(['id' => 'c1'], $this->readyReadiness(), $this->validLaneFacts());

        $this->assertNotEmpty($r['rollback_hooks']);
    }

    public function test_valid_lane_has_knowledge_sync_hook(): void
    {
        $r = $this->planner()->plan(['id' => 'c1'], $this->readyReadiness(), $this->validLaneFacts());

        $this->assertNotEmpty($r['knowledge_sync_hooks']);
    }

    public function test_valid_lane_requires_no_operator_handoff(): void
    {
        $r = $this->planner()->plan(['id' => 'c1'], $this->readyReadiness(), $this->validLaneFacts());

        $this->assertFalse($r['requires_operator_handoff']);
    }
}

// Small local helper — avoids loading any extra dependencies.
function array_some_contains(array $arr, string $needle): bool
{
    foreach ($arr as $item) {
        if (str_contains((string) $item, $needle)) {
            return true;
        }
    }
    return false;
}
