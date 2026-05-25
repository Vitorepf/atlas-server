<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendBenchmarkRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Tests\TestCase;

class AtlasFrontendBenchmarkRuntimeServiceTest extends TestCase
{
    public function test_benchmark_is_honest_about_contract_superiority_and_world_best_claim(): void
    {
        $payload = app(AtlasFrontendBenchmarkRuntimeService::class)->run();

        $this->assertSame('atlas.frontend.benchmark_runtime.v1', $payload['schema_version']);
        $this->assertSame('documentation_backed_static_runtime_matrix', $payload['benchmark_type']);
        $this->assertFalse((bool) data_get($payload, 'scope.uses_live_external_rival_execution'));
        $this->assertFalse((bool) data_get($payload, 'scope.rival_evidence_directory_supplied'));
        $this->assertTrue((bool) data_get($payload, 'claims.atlas_more_complete_than_impeccable_on_governed_delivery_contract'));
        $this->assertTrue((bool) data_get($payload, 'claims.atlas_more_complete_than_claude_design_plugin_on_governed_delivery_contract'));
        $this->assertFalse((bool) data_get($payload, 'claims.atlas_live_mode_superior_to_impeccable'));
        $this->assertFalse((bool) data_get($payload, 'claims.atlas_world_best_frontend_system'));
        $this->assertTrue((bool) data_get($payload, 'scope.rival_replay_harness_present'));
        $this->assertFalse((bool) data_get($payload, 'scope.external_rival_replay_completed'));
        $this->assertSame('pending_replay', data_get($payload, 'scope.rival_replay_competitive_diagnostics_status'));
        $this->assertSame(0, data_get($payload, 'scope.rival_replay_tied_case_count'));
        $this->assertSame(0, data_get($payload, 'scope.rival_replay_dimension_gap_case_count'));
        $this->assertFalse((bool) data_get($payload, 'scope.rival_replay_decisive_lead_ready'));
        $this->assertSame('atlas.frontend.rival_replay_harness.v1', data_get($payload, 'rival_replay.schema_version'));
        $this->assertSame('pending_replay', data_get($payload, 'rival_replay.competitive_diagnostics.status'));
        $this->assertNotContains('framework_hmr_adapters_required_for_live_mode_superiority', $payload['remaining_gaps']);
        $this->assertContains('external_rival_replay_artifacts_required_for_world_best_claim', $payload['remaining_gaps']);
        $this->assertGreaterThan(data_get($payload, 'totals.pbakaus_impeccable.score'), data_get($payload, 'totals.atlas_frontend.score'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['benchmark_hash']);
    }

    public function test_benchmark_uses_supplied_rival_evidence_directory_for_replay_signal(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-benchmark-rival-evidence-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        $payload = app(AtlasFrontendBenchmarkRuntimeService::class)->run($dir);

        $this->assertTrue((bool) data_get($payload, 'scope.rival_evidence_directory_supplied'));
        $this->assertSame(hash('sha256', $dir), data_get($payload, 'scope.rival_evidence_directory_hash'));
        $this->assertSame('ready_for_replay', data_get($payload, 'rival_replay.status'));
        $this->assertSame('pending_replay', data_get($payload, 'rival_replay.competitive_diagnostics.status'));
        $this->assertSame(15, data_get($payload, 'rival_replay.summary.missing_or_pending'));
        $this->assertFalse((bool) data_get($payload, 'claims.atlas_world_best_frontend_system'));
        $this->assertFalse((bool) data_get($payload, 'claims.atlas_decisively_leads_verified_rival_replay'));
    }

    public function test_benchmark_carries_runtime_evidence_hashes(): void
    {
        $payload = app(AtlasFrontendBenchmarkRuntimeService::class)->run();

        $this->assertTrue((bool) data_get($payload, 'evidence.frontend_contract.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.task_spec_compiler.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.framework_adapter.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.design_system_inventory.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.execution_gate.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.repair_planner.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.run_certification.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.product_proof.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.company_design_profile.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.design_direction_advisor.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.asset_pack_verifier.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.design_review.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.visual_quality_gate.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.design_system_drift_gate.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.publication_verifier.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.evidence_pack_verifier.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.outcome_memory.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.competitive_rubric.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.rival_replay_harness.present'));
        $this->assertTrue((bool) data_get($payload, 'evidence.impeccable_coverage_audit.present'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'evidence.frontend_contract.content_hash'));
    }
}
