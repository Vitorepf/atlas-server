<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveBenchmarkPlanService;
use Tests\TestCase;

class AtlasFrontendCompetitiveBenchmarkPlanServiceTest extends TestCase
{
    public function test_private_plan_tracks_impeccable_gap_without_authorizing_public_claims(): void
    {
        $payload = app(AtlasFrontendCompetitiveBenchmarkPlanService::class)->plan();

        $this->assertSame('atlas.frontend.competitive_benchmark_plan.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('private_competitive_benchmark_and_improvement_loop', $payload['plan_type']);
        $this->assertSame('pbakaus_impeccable', data_get($payload, 'intent.primary_rival'));
        $this->assertFalse((bool) data_get($payload, 'intent.public_marketing_claim_goal'));
        $this->assertTrue((bool) data_get($payload, 'private_policy.atlas_is_private_operator_tool'));
        $this->assertTrue((bool) data_get($payload, 'private_policy.benchmark_is_for_internal_improvement'));
        $this->assertTrue((bool) data_get($payload, 'private_policy.public_claims_disabled'));
        $this->assertFalse((bool) data_get($payload, 'private_policy.world_best_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'benchmark.claims.atlas_more_complete_than_impeccable_on_governed_delivery_contract'));
        $this->assertFalse((bool) data_get($payload, 'benchmark.claims.atlas_world_best_frontend_system'));

        $liveGap = collect($payload['scenario_gaps'])->firstWhere('scenario_id', 'live_visual_iteration');
        $this->assertSame('atlas_lags', $liveGap['status']);
        $this->assertSame('pbakaus_impeccable', $liveGap['best_rival_system']);
        $this->assertSame(2, $liveGap['minimum_points_to_lead']);

        $this->assertSame('improve_live_visual_iteration', data_get($payload, 'next_private_improvement_action.id'));
        $this->assertSame('atlas.frontend.private_improvement_work_packet.v1', data_get($payload, 'next_private_work_packet.schema_version'));
        $this->assertSame('private_improvement_live_visual_iteration', data_get($payload, 'next_private_work_packet.packet_id'));
        $this->assertSame('live_visual_iteration', data_get($payload, 'next_private_work_packet.target_scenario_id'));
        $this->assertContains('selected_repository_workspace', data_get($payload, 'next_private_work_packet.required_context'));
        $this->assertContains('live_patch_decision_receipt_recorded_when_source_changes', data_get($payload, 'next_private_work_packet.success_criteria'));
        $this->assertContains('repair_live_patch_boundary', data_get($payload, 'next_private_work_packet.repair_plan.repair_step_ids'));
        $this->assertContains('visual_quality_gate', data_get($payload, 'next_private_work_packet.repair_plan.rerun_gates'));
        $this->assertTrue((bool) data_get($payload, 'next_private_work_packet.claim_policy.operator_private_improvement_only'));
        $this->assertFalse((bool) data_get($payload, 'next_private_work_packet.claim_policy.raw_source_or_absolute_paths_returned'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'next_private_work_packet.work_packet_hash'));
        $this->assertSame(data_get($payload, 'next_private_work_packet.work_packet_hash'), data_get($payload, 'evidence_hashes.next_private_work_packet_hash'));
        $this->assertContains('private_rival_replay_evidence_incomplete', $payload['blockers']);
        $this->assertContains('private_competitive_improvement_queue_not_empty', $payload['blockers']);
        $this->assertContains('complete_private_rival_replay_evidence', $payload['required_next_actions']);
        $this->assertContains('rerun_competitive_benchmark_plan', $payload['required_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['competitive_benchmark_plan_hash']);
    }
}
