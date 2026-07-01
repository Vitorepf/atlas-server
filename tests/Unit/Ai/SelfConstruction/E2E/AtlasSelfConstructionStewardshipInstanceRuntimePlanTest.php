<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\E2E;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionStewardshipInstanceRuntimePlan;
use Tests\TestCase;

final class AtlasSelfConstructionStewardshipInstanceRuntimePlanTest extends TestCase
{
    private function atlasLane(): array
    {
        return [
            'admitted' => true,
            'project_id' => 'atlas-server',
            'repo_root' => '/repos/atlas-server',
            'mainline_branch' => 'main',
            'allowed_scope_roots' => ['/repos/atlas-server/app', '/repos/atlas-server/tests'],
            'forbidden_paths' => ['/repos/atlas-server/config/atlas.php'],
            'verification_commands' => ['php artisan test'],
            'merge_policy' => ['mode' => 'shared_main_with_scope_lock'],
            'knowledge_sync_policy' => ['targets' => ['docs', 'code_index', 'context_pack']],
        ];
    }

    private function externalLane(): array
    {
        return [
            'admitted' => true,
            'project_id' => 'partner-x',
            'repo_root' => '/repos/partner-x',
            'mainline_branch' => 'release',
            'allowed_scope_roots' => ['/repos/partner-x/src'],
            'forbidden_paths' => [],
            'verification_commands' => ['npm test'],
            'merge_policy' => ['mode' => 'merge_via_pull_request'],
            'knowledge_sync_policy' => ['targets' => ['docs', 'code_index']],
        ];
    }

    private function coverFull(): array
    {
        return ['fully_covered' => true];
    }

    public function test_atlas_lane_yields_atlas_specific_runtime_plan(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($this->atlasLane(), $this->coverFull());

        $this->assertFalse($verdict['refused']);
        $this->assertSame([], $verdict['blockers']);
        $plan = $verdict['runtime_plan'];
        $this->assertSame('atlas-server', $verdict['project_id']);
        $this->assertSame('/repos/atlas-server', $plan['repository_root']);
        $this->assertSame('main', $plan['mainline_branch']);
        $this->assertSame('atlas:queue:atlas-server', $plan['task_queue_namespace']);
        $this->assertSame('storage/atlas/stewardship/atlas-server/receipts.jsonl', $plan['receipts_path']);
        $this->assertSame('shared_local_main_with_scope_lock', $plan['workspace_topology']);
        $this->assertContains('/repos/atlas-server/config/atlas.php', $plan['forbidden_paths']);
    }

    public function test_external_lane_yields_partner_specific_runtime_plan(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($this->externalLane(), $this->coverFull());

        $this->assertFalse($verdict['refused']);
        $this->assertSame('partner-x', $verdict['project_id']);
        $plan = $verdict['runtime_plan'];
        $this->assertSame('/repos/partner-x', $plan['repository_root']);
        $this->assertSame('release', $plan['mainline_branch']);
        $this->assertSame('merge_via_pull_request', $plan['mainline_policy']);
        $this->assertSame('atlas:queue:partner-x', $plan['task_queue_namespace']);
        $this->assertSame(['npm test'], $plan['verification_gates']);
    }

    public function test_cross_project_scope_escape_is_refused(): void
    {
        $lane = $this->atlasLane();
        $lane['allowed_scope_roots'] = ['/repos/atlas-server/app', '/repos/partner-x/src'];

        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($lane, $this->coverFull());

        $this->assertTrue($verdict['refused']);
        $this->assertContains('cross_project_scope_escape:/repos/partner-x/src', $verdict['blockers']);
        $this->assertNull($verdict['runtime_plan']);
    }

    public function test_non_admitted_lane_is_refused(): void
    {
        $lane = $this->atlasLane();
        $lane['admitted'] = false;

        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($lane, $this->coverFull());
        $this->assertTrue($verdict['refused']);
        $this->assertContains('lane_not_admitted', $verdict['blockers']);
    }

    public function test_incomplete_organ_coverage_is_refused(): void
    {
        $coverage = ['fully_covered' => false, 'missing_organ' => ['cortex']];

        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($this->atlasLane(), $coverage);
        $this->assertTrue($verdict['refused']);
        $this->assertContains('organ_coverage_incomplete', $verdict['blockers']);
        $this->assertContains('coverage:missing_organ:cortex', $verdict['blockers']);
    }

    public function test_missing_project_id_or_repo_root_is_refused(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose(['admitted' => true], $this->coverFull());
        $this->assertTrue($verdict['refused']);
        $this->assertContains('project_id_missing', $verdict['blockers']);
        $this->assertContains('repo_root_missing', $verdict['blockers']);
    }

    public function test_empty_verification_commands_is_refused(): void
    {
        $lane = $this->atlasLane();
        $lane['verification_commands'] = [];
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($lane, $this->coverFull());
        $this->assertTrue($verdict['refused']);
        $this->assertContains('verification_commands_empty', $verdict['blockers']);
    }

    public function test_missing_docs_in_knowledge_sync_targets_is_refused(): void
    {
        $lane = $this->atlasLane();
        $lane['knowledge_sync_policy'] = ['targets' => ['code_index']];
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($lane, $this->coverFull());
        $this->assertTrue($verdict['refused']);
        $this->assertContains('knowledge_sync_target_missing:docs', $verdict['blockers']);
    }

    public function test_missing_code_index_in_knowledge_sync_targets_is_refused(): void
    {
        $lane = $this->atlasLane();
        $lane['knowledge_sync_policy'] = ['targets' => ['docs']];
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($lane, $this->coverFull());
        $this->assertTrue($verdict['refused']);
        $this->assertContains('knowledge_sync_target_missing:code_index', $verdict['blockers']);
    }

    public function test_runtime_plan_includes_deterministic_queue_namespace_receipts_and_sync_targets(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($this->atlasLane(), $this->coverFull());
        $this->assertFalse($verdict['refused']);
        $plan = $verdict['runtime_plan'];
        $this->assertSame('atlas:queue:atlas-server', $plan['task_queue_namespace']);
        $this->assertSame('storage/atlas/stewardship/atlas-server/receipts.jsonl', $plan['receipts_path']);
        $this->assertContains('docs', $plan['knowledge_sync_targets']);
        $this->assertContains('code_index', $plan['knowledge_sync_targets']);
    }

    // ── AC: autonomy level, evidence contract, worker mix, knowledge-sync duties, stop/go criteria ──

    public function test_plan_includes_autonomy_evidence_contract_worker_mix_sync_duties_and_stop_go_criteria(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($this->atlasLane(), $this->coverFull());
        $plan = $verdict['runtime_plan'];

        $this->assertArrayHasKey('autonomy_level', $plan);
        $this->assertArrayHasKey('evidence_contract', $plan);
        $this->assertArrayHasKey('worker_mix', $plan);
        $this->assertArrayHasKey('knowledge_sync_duties', $plan);
        $this->assertArrayHasKey('stop_go_criteria', $plan);
        $this->assertNotEmpty($plan['evidence_contract']);
        $this->assertNotEmpty($plan['stop_go_criteria']);
        $this->assertContains('docs', $plan['knowledge_sync_duties']);
        $this->assertContains('code_index', $plan['knowledge_sync_duties']);
    }

    public function test_default_autonomy_level_is_supervised_when_not_requested(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($this->atlasLane(), $this->coverFull());

        $this->assertSame(
            AtlasSelfConstructionStewardshipInstanceRuntimePlan::AUTONOMY_SUPERVISED,
            $verdict['runtime_plan']['autonomy_level'],
        );
    }

    public function test_unsafe_full_autonomous_request_without_safety_contract_is_downgraded_to_supervised(): void
    {
        $lane = $this->atlasLane();
        $lane['autonomy_level'] = AtlasSelfConstructionStewardshipInstanceRuntimePlan::AUTONOMY_FULL_AUTONOMOUS;
        // safety_contract_complete NOT set — unsafe request.

        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($lane, $this->coverFull());

        $this->assertFalse($verdict['refused']);
        $this->assertSame(
            AtlasSelfConstructionStewardshipInstanceRuntimePlan::AUTONOMY_SUPERVISED,
            $verdict['runtime_plan']['autonomy_level'],
            'full_autonomous without an explicit safety contract must be downgraded, not silently honored',
        );
    }

    public function test_full_autonomous_request_with_safety_contract_complete_is_honored(): void
    {
        $lane = $this->atlasLane();
        $lane['autonomy_level'] = AtlasSelfConstructionStewardshipInstanceRuntimePlan::AUTONOMY_FULL_AUTONOMOUS;
        $lane['safety_contract_complete'] = true;

        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($lane, $this->coverFull());

        $this->assertSame(
            AtlasSelfConstructionStewardshipInstanceRuntimePlan::AUTONOMY_FULL_AUTONOMOUS,
            $verdict['runtime_plan']['autonomy_level'],
        );
    }

    public function test_worker_mix_defaults_to_atlas_native_only(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->compose($this->atlasLane(), $this->coverFull());

        $this->assertSame(['atlas_native' => 1, 'external_provider_worker' => 0], $verdict['runtime_plan']['worker_mix']);
    }

    public function test_external_lane_e2e_scenario_composition_carries_new_plan_fields(): void
    {
        $verdict = (new AtlasSelfConstructionStewardshipInstanceRuntimePlan)->composeWithE2eScenario($this->externalLane(), $this->coverFull());

        $this->assertFalse($verdict['refused']);
        $this->assertArrayHasKey('e2e_cycle_scenario', $verdict);
        $this->assertArrayHasKey('autonomy_level', $verdict['runtime_plan']);
        $this->assertArrayHasKey('worker_mix', $verdict['runtime_plan']);
    }
}
