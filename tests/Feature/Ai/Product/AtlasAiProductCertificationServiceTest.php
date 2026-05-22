<?php

namespace Tests\Feature\Ai\Product;

use App\Services\Ai\Product\AtlasAiProductCertificationService;
use Tests\TestCase;

/**
 * Atlas AI · Product Certification (E2E plumbing) — service-level test.
 *
 * Pairs with `AtlasAiProductCertifyCommandTest` (CLI roundtrip) and with
 * the existing `AtlasAiInteractionHyperflowEntryTest` (real Hyperflow
 * routing scenarios). This test guards the cert envelope itself: shape,
 * checks, hash stability, claims.
 *
 * It does NOT invoke any provider, run rivals, or benchmark — the cert
 * is a static file-inspection read model.
 */
class AtlasAiProductCertificationServiceTest extends TestCase
{
    public function test_envelope_shape_is_stable(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();

        $this->assertSame(
            AtlasAiProductCertificationService::SCHEMA_VERSION,
            $report['schema_version'],
        );
        $this->assertContains($report['status'], ['ready', 'partial', 'blocked']);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertArrayHasKey('remaining_blockers', $report);
        $this->assertArrayHasKey('evidence_refs', $report);
        $this->assertArrayHasKey('claims', $report);
        $this->assertArrayHasKey('certification_hash', $report);
        $this->assertSame(64, strlen((string) $report['certification_hash']));
        $this->assertSame(20, count($report['checks']));
        $this->assertFalse($report['writes']);
    }

    public function test_claims_block_declares_no_provider_no_rivals_no_benchmark_no_superiority(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();

        $this->assertFalse($report['claims']['declares_teos']);
        $this->assertFalse($report['claims']['declares_benchmark']);
        $this->assertFalse($report['claims']['declares_superiority']);
        $this->assertFalse($report['claims']['invokes_provider']);
        $this->assertFalse($report['claims']['runs_rivals']);
        $this->assertSame('product_runtime_governance', $report['claims']['scope']);
        $this->assertTrue($report['claims']['covers_desktop_runtime_ux']);
        $this->assertTrue($report['claims']['covers_agent_control_plane_runtime']);
        $this->assertTrue($report['claims']['covers_external_execution_governance']);
        $this->assertTrue($report['claims']['covers_internal_autonomous_company_runtime']);
        $this->assertTrue($report['claims']['covers_capability_usage_evolution']);
        $this->assertTrue($report['claims']['covers_code_intelligence_automatic_gate']);
        $this->assertTrue($report['claims']['covers_verified_context_execution_loop']);
        $this->assertTrue($report['claims']['covers_assisted_execution_quality']);
    }

    public function test_certification_hash_is_deterministic_across_runs(): void
    {
        $service = app(AtlasAiProductCertificationService::class);
        $first = $service->certify();
        $second = $service->certify();

        $this->assertSame(
            $first['certification_hash'],
            $second['certification_hash'],
            'hash must drop generated_at and stay stable for the same tree state',
        );
    }

    public function test_all_canonical_checks_pass_on_current_tree(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $expected = [
            'hyperflow_v2_entry',
            'ai_interactions_preserves_rich_input',
            'universal_composer_canon_present',
            'mobile_uses_canon',
            'desktop_uses_canon',
            'forge_accepts_canon',
            'presentation_contract',
            'context_trace_audit_consumes_technical_data',
            'specialist_flows_registered',
            'routing_anti_regression_tests_present',
            'forge_strips_raw_text_to_hash_and_derives_context_refs',
            'no_attachment_path_still_works',
            'desktop_control_plane_runtime_governance_ux',
            'agent_control_plane_runtime_standard',
            'governed_external_execution_control_plane',
            'internal_autonomous_company_runtime_claim_gate',
            'capability_usage_and_evolution_loop',
            'code_intelligence_automatic_gate',
            'verified_context_execution_loop',
            'assisted_execution_quality',
        ];

        foreach ($expected as $id) {
            $this->assertArrayHasKey($id, $byId, "missing canonical check `{$id}`");
            $this->assertSame(
                'passed',
                $byId[$id]['status'],
                "check `{$id}` regressed — see evidence: ".json_encode($byId[$id]['evidence'] ?? []),
            );
        }

        $this->assertSame('ready', $report['status']);
        $this->assertSame([], $report['remaining_blockers']);
    }

    public function test_each_check_has_severity_and_evidence(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();

        foreach ($report['checks'] as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('severity', $check);
            $this->assertArrayHasKey('evidence', $check);
            $this->assertContains($check['status'], ['passed', 'failed']);
            $this->assertContains($check['severity'], ['critical', 'warn']);
            $this->assertIsArray($check['evidence']);
        }
    }

    public function test_evidence_refs_include_all_four_surfaces(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $refs = $report['evidence_refs'];

        $this->assertNotEmpty(array_filter($refs, static fn (string $ref): bool => str_starts_with($ref, 'server:')));
        $this->assertNotEmpty(array_filter($refs, static fn (string $ref): bool => str_starts_with($ref, 'mobile:')));
        $this->assertNotEmpty(array_filter($refs, static fn (string $ref): bool => str_starts_with($ref, 'desktop:')));
        $this->assertNotEmpty(array_filter($refs, static fn (string $ref): bool => str_starts_with($ref, 'canon:')));
        $this->assertNotEmpty(array_filter($refs, static fn (string $ref): bool => str_starts_with($ref, 'test:')));
    }

    public function test_e2e_scenario_coverage_is_documented_in_evidence_refs(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $refs = implode('|', $report['evidence_refs']);

        // Backend Hyperflow entry test covers: ambiguous prompt / research /
        // finance / programming / Obra / URL+YouTube + rich_input_payload
        // preservation. Cert must point at it as authoritative E2E evidence.
        $this->assertStringContainsString(
            'AtlasAiInteractionHyperflowEntryTest.php',
            $refs,
            'Hyperflow entry test must be referenced — it is the E2E routing scenarios source of truth',
        );

        // Desktop anti-regression covers: research not programming.dev,
        // finance not atlas_dev, programming → atlas_dev, Obra → atlas_forge.
        $this->assertStringContainsString(
            'AtlasAiDesktopHyperflowAntiRegressionTest.php',
            $refs,
            'Desktop anti-regression test must be referenced — it locks routing invariants',
        );

        // Desktop runtime trip covers: composer→request→view-model→presentation→audit.
        $this->assertStringContainsString(
            'desktopRuntimeFinalCertification.test.ts',
            $refs,
            'Desktop full-trip test must be referenced',
        );

        // Forge rich input/intake tests cover: payload canon acceptance,
        // raw text hashing, source_manifest → context_refs.
        $this->assertStringContainsString(
            'AtlasCodeWorkRichInputTest.php',
            $refs,
            'Forge rich input test must be referenced',
        );
        $this->assertStringContainsString(
            'ForgeIntakeServiceTest.php',
            $refs,
            'Forge intake test must be referenced',
        );
    }

    public function test_evidence_includes_canonical_5_stage_pipeline(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $pipeline = $byId['hyperflow_v2_entry']['evidence']['pipeline_stages'];
        $this->assertTrue($pipeline['IntentKernelService']);
        $this->assertTrue($pipeline['DomainRouterService']);
        $this->assertTrue($pipeline['FlowRouterService']);
        $this->assertTrue($pipeline['RuntimeDispatchService']);
        $this->assertTrue($pipeline['DecisionReceiptService']);
    }

    public function test_evidence_proves_rich_input_merge_happens_before_hyperflow_run(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $evidence = $byId['ai_interactions_preserves_rich_input']['evidence'];
        $this->assertTrue($evidence['controller_extracts_rich_input']);
        $this->assertTrue($evidence['controller_merges_into_payload']);
        $this->assertTrue($evidence['controller_passes_to_hyperflow']);
        $this->assertTrue(
            $evidence['merge_happens_before_hyperflow_run'],
            'merge must happen BEFORE the Hyperflow entry runs, not after',
        );
        $this->assertTrue($evidence['request_validates_schema_version']);
        $this->assertTrue($evidence['request_validates_canonical_shape']);
    }

    public function test_evidence_proves_forge_does_not_auto_ready_from_attachment_alone(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $forge = $byId['forge_accepts_canon']['evidence'];
        $this->assertTrue($forge['intake_service_has_normalizer']);
        $this->assertTrue($forge['intake_service_defaults_canon_schema']);
        $this->assertTrue($forge['forge_work_intake_ready_is_not_set_by_attachment_alone']);
    }

    public function test_evidence_proves_presentation_contract_is_wired_on_both_surfaces(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $pc = $byId['presentation_contract']['evidence'];
        $this->assertTrue($pc['mobile_module_present']);
        $this->assertTrue($pc['desktop_module_present']);
        $this->assertTrue($pc['mobile_wired_in_turn_model'], 'mobile must call projectPresentation in turn model');
        $this->assertTrue($pc['desktop_wired_in_conversation'], 'desktop must call projectPresentation in conversation');
        $this->assertTrue($pc['mobile_separates_technical_sections']);
        $this->assertTrue($pc['desktop_separates_technical_sections']);
    }

    public function test_evidence_proves_desktop_control_plane_exposes_runtime_governance(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $evidence = $byId['desktop_control_plane_runtime_governance_ux']['evidence'];
        $this->assertTrue($evidence['reads_external_execution_unsafe_enabled']);
        $this->assertTrue($evidence['reads_external_execution_missing_receipt_bindings']);
        $this->assertTrue($evidence['renders_unsafe_execution_metric']);
        $this->assertTrue($evidence['renders_receipt_gap_metric']);
        $this->assertTrue($evidence['renders_signature_coverage']);
        $this->assertTrue($evidence['renders_blocked_by_default_policy']);
        $this->assertTrue($evidence['governance_strip_styled']);
    }

    public function test_evidence_proves_external_execution_is_governed(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $evidence = $byId['governed_external_execution_control_plane']['evidence'];
        $this->assertTrue($evidence['tracks_unsafe_external_execution']);
        $this->assertTrue($evidence['tracks_missing_receipt_bindings']);
        $this->assertTrue($evidence['unsafe_execution_blocks_runtime_status']);
        $this->assertTrue($evidence['manual_handoff_only_policy_present']);
        $this->assertTrue($evidence['pending_approval_is_operator_queue_policy_present']);
        $this->assertTrue($evidence['pending_approval_test_present']);
        $this->assertTrue($evidence['unsafe_execution_blocker_test_present']);
    }

    public function test_evidence_proves_agent_control_plane_runtime_standard(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $evidence = $byId['agent_control_plane_runtime_standard']['evidence'];
        $this->assertTrue($evidence['orchestrates_task_queue_and_claim_leases']);
        $this->assertTrue($evidence['multi_agent_loop_certified']);
        $this->assertTrue($evidence['task_packets_have_acceptance_and_evidence']);
        $this->assertTrue($evidence['leases_govern_ownership_and_disable_dispatch']);
        $this->assertTrue($evidence['orchestrator_test_present']);
        $this->assertTrue($evidence['multi_agent_loop_test_present']);
        $this->assertTrue($evidence['task_packet_test_present']);
        $this->assertTrue($evidence['claim_lease_test_present']);
    }

    public function test_evidence_proves_internal_autonomous_company_claim_gate(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $evidence = $byId['internal_autonomous_company_runtime_claim_gate']['evidence'];
        $this->assertTrue($evidence['certifies_all_roles_have_agent_task_packets']);
        $this->assertTrue($evidence['internal_autonomous_company_claim_present']);
        $this->assertTrue($evidence['external_superiority_claim_blocked']);
        $this->assertTrue($evidence['external_benchmark_claim_blocked']);
        $this->assertTrue($evidence['feature_test_covers_claim_gate']);
        $this->assertTrue($evidence['unit_test_covers_claim_gate']);
    }

    public function test_evidence_proves_capability_usage_and_evolution_loop(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $evidence = $byId['capability_usage_and_evolution_loop']['evidence'];
        $this->assertTrue($evidence['control_plane_tracks_capability_used_events']);
        $this->assertTrue($evidence['control_plane_tracks_evolution_events']);
        $this->assertTrue($evidence['control_plane_tests_cover_counters']);
        $this->assertTrue($evidence['aemor_creates_intelligence_factory_evolution_candidate']);
        $this->assertTrue($evidence['aemor_test_covers_evolution_candidate']);
        $this->assertTrue($evidence['intelligence_factory_records_usage']);
        $this->assertTrue($evidence['intelligence_factory_certifies_registry_and_evolution_tables']);
    }

    public function test_evidence_proves_assisted_execution_quality_is_wired(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $evidence = $byId['assisted_execution_quality']['evidence'];
        $this->assertTrue($evidence['service_builds_human_execution_envelope']);
        $this->assertTrue($evidence['service_routes_dev_and_forge']);
        $this->assertTrue($evidence['service_uses_dev_runtime_context_gate']);
        $this->assertTrue($evidence['service_protects_login_bug_human_path']);
        $this->assertTrue($evidence['ai_interaction_controller_wired_before_dev_runtime']);
        $this->assertTrue($evidence['ai_interaction_controller_enforces_context_gate']);
        $this->assertTrue($evidence['tests_cover_core_paths']);
        $this->assertTrue($evidence['canonical_doc_present']);
    }
}
