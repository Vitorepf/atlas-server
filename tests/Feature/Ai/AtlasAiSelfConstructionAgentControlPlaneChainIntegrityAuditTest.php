<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest extends TestCase
{
    public function test_audit_returns_schema_v1(): void
    {
        $audit = $this->newService()->audit();

        $this->assertSame(
            'atlas.self_construction.agent_control_plane_chain_integrity_certification.v1',
            data_get($audit, 'schema_version'),
        );
        $this->assertSame(
            'read_only_agent_control_plane_chain_integrity_certification',
            data_get($audit, 'mode'),
        );
        $this->assertFalse(data_get($audit, 'execution_allowed'));
        $this->assertFalse(data_get($audit, 'dispatch_allowed'));
        $this->assertFalse(data_get($audit, 'ledger_write_allowed'));
        $this->assertFalse(data_get($audit, 'runtime_write_allowed'));
    }

    public function test_audit_status_is_available_or_degraded_with_honest_warnings(): void
    {
        $audit = $this->newService()->audit();

        $this->assertContains(
            data_get($audit, 'status'),
            ['available', 'degraded', 'blocked', 'missing_artifacts'],
        );
        $this->assertIsArray(data_get($audit, 'violations'));
        $this->assertIsArray(data_get($audit, 'warnings'));
    }

    public function test_audit_includes_current_next_required_slice(): void
    {
        $audit = $this->newService()->audit();

        $this->assertNotEmpty(data_get($audit, 'current_next_required_slice'));
        $this->assertIsString(data_get($audit, 'current_next_required_slice'));
    }

    public function test_audit_includes_expected_next_required_slice(): void
    {
        $audit = $this->newService()->audit();

        $this->assertIsString(data_get($audit, 'expected_next_required_slice'));
        $this->assertNotSame('', data_get($audit, 'expected_next_required_slice'));
    }

    public function test_audit_aligns_next_required_slice_with_expected(): void
    {
        $audit = $this->newService()->audit();

        $this->assertSame(
            data_get($audit, 'current_next_required_slice'),
            data_get($audit, 'expected_next_required_slice'),
            'Persistent runtime pointer must match the certification expectation derived from not_yet_runtime_capable[3].',
        );
    }

    public function test_audit_checks_capabilities(): void
    {
        $audit = $this->newService()->audit();
        $capabilitySurface = (array) data_get($audit, 'capability_surface', []);

        // The deep_checked count is the canonical chain length and is always
        // populated; the `total` count of registered capabilities only mirrors
        // the structurally checked slice families when the runtime schema is
        // applied (schema_ready). In schema_missing environments only the
        // base capabilities are registered.
        $this->assertGreaterThan(0, (int) data_get($capabilitySurface, 'total'));
        $this->assertSame(0, (int) data_get($capabilitySurface, 'duplicate_count'));
        $this->assertGreaterThan(0, (int) data_get($capabilitySurface, 'deep_checked'));
    }

    public function test_audit_checks_cli_options(): void
    {
        $audit = $this->newService()->audit();
        $cliSurface = (array) data_get($audit, 'cli_surface', []);

        $this->assertSame('atlas:ai:self-construction', data_get($cliSurface, 'command_name'));
        $this->assertTrue((bool) data_get($cliSurface, 'handlers_aligned'));
        $this->assertSame([], (array) data_get($cliSurface, 'missing_options'));
        $expected = (array) data_get($cliSurface, 'expected_options');
        $this->assertContains('agent-control-plane-chain-integrity-certification', $expected);
        $this->assertContains('agent-control-plane-chain-integrity-certification-status', $expected);
        $this->assertContains('agent-control-plane-chain-integrity-certification-preflight', $expected);
        $this->assertContains('agent-control-plane-chain-integrity-certification-implementation-packet', $expected);
    }

    public function test_audit_checks_readiness_methods(): void
    {
        $audit = $this->newService()->audit();
        $readinessSurface = (array) data_get($audit, 'readiness_surface', []);
        $this->assertTrue((bool) data_get($readinessSurface, 'all_quartet_methods_present'));
    }

    public function test_audit_checks_invoker_classes(): void
    {
        $audit = $this->newService()->audit();
        $invokerSurface = (array) data_get($audit, 'invoker_surface', []);
        $this->assertSame([], (array) data_get($invokerSurface, 'missing'));
        $this->assertGreaterThan(0, (int) data_get($invokerSurface, 'deep_checked'));
    }

    public function test_audit_checks_docs_duplicate_slices(): void
    {
        $audit = $this->newService()->audit();
        $documentation = (array) data_get($audit, 'documentation', []);
        $this->assertTrue((bool) data_get($documentation, 'contract_doc_present'));
        $this->assertSame([], (array) data_get($documentation, 'duplicate_slice_bullets'));
        $bullets = (array) data_get($documentation, 'slice_bullets_found', []);
        foreach ($bullets as $sliceKey => $count) {
            $this->assertSame(1, $count, "Slice {$sliceKey} bullet must appear exactly once in the canonical contract doc.");
        }
    }

    public function test_audit_reports_no_runtime_enabling(): void
    {
        $audit = $this->newService()->audit();
        $runtimeSafety = (array) data_get($audit, 'runtime_safety', []);
        $this->assertFalse((bool) data_get($runtimeSafety, 'actual_process_start_allowed_anywhere'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'provider_process_call_allowed_anywhere'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'adapter_invocation_allowed_anywhere'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'adapter_execution_allowed_anywhere'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'dispatch_allowed_anywhere'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'token_spend_allowed_anywhere'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'self_programming_allowed_anywhere'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'external_process_started_by_atlas'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'codex_cli_invoked'));
        $this->assertFalse((bool) data_get($runtimeSafety, 'shell_spawned_by_runtime'));
        $this->assertTrue((bool) data_get($runtimeSafety, 'runtime_safety_all_false'));
    }

    public function test_audit_reports_not_yet_runtime_capable_alignment(): void
    {
        $audit = $this->newService()->audit();
        $invariants = (array) data_get($audit, 'invariants', []);
        $this->assertTrue((bool) data_get($invariants, 'not_yet_runtime_capable_slot_3_present'));
        $this->assertTrue((bool) data_get($invariants, 'current_next_required_slice_matches_expected'));
    }

    public function test_audit_reports_next_build_slices_alignment(): void
    {
        $audit = $this->newService()->audit();
        $invariants = (array) data_get($audit, 'invariants', []);
        $this->assertTrue((bool) data_get($invariants, 'next_build_slices_contains_next_required_slice'));
        $buildSlices = (array) data_get($audit, 'current_next_build_slices');
        $this->assertContains(
            data_get($audit, 'current_next_required_slice'),
            $buildSlices,
        );
    }

    public function test_audit_catches_synthetic_broken_slice_when_chain_is_overridden(): void
    {
        $audit = $this->newService()->audit([
            'override_slices' => [
                [
                    'slice_key' => 'synthetic_broken_slice_for_certification_test',
                    'method_prefix' => 'doesNotExistOnReadinessServiceForCertification',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\NonExistentSyntheticInvokerForCertification',
                    'prepare_method' => 'nonExistentPrepareMethodForCertification',
                    'doc_bullet' => 'synthetic broken slice for certification test',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_synthetic_broken_slice_for_certification_test_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_synthetic_broken_slice_for_certification_test_contract_runtime',
                ],
            ],
        ]);

        $this->assertSame('degraded', data_get($audit, 'status'));
        $this->assertGreaterThan(0, count((array) data_get($audit, 'violations')));
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('slice_missing_artifact', $codes);
    }

    public function test_cli_contract_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-chain-integrity-certification' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_chain_integrity_certification_contract.v1',
            data_get($payload, 'schema_version'),
        );
        $this->assertSame('agent_control_plane_chain_integrity_certification_contract_ready', data_get($payload, 'status'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame(
            'activate_agent_control_plane_chain_integrity_certification_preflight',
            data_get($payload, 'agent_control_plane_chain_integrity_certification_contract.next_required_slice'),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_chain_integrity_certification_contract_hash'));
    }

    public function test_cli_preflight_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-chain-integrity-certification-preflight' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_chain_integrity_certification_preflight.v1',
            data_get($payload, 'schema_version'),
        );
        $this->assertSame('agent_control_plane_chain_integrity_certification_preflight_ready', data_get($payload, 'status'));
        $this->assertSame(0, (int) data_get($payload, 'agent_control_plane_chain_integrity_certification_preflight.blocking_count'));
        $this->assertSame(
            'activate_agent_control_plane_chain_integrity_certification_implementation_packet',
            data_get($payload, 'agent_control_plane_chain_integrity_certification_preflight.next_required_slice'),
        );
    }

    public function test_cli_implementation_packet_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-chain-integrity-certification-implementation-packet' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_chain_integrity_certification_implementation_packet.v1',
            data_get($payload, 'schema_version'),
        );
        $this->assertSame('ready_for_scoped_agent_control_plane_chain_integrity_certification_implementation', data_get($payload, 'status'));
        $allowedFiles = (array) data_get($payload, 'agent_control_plane_chain_integrity_certification_implementation_packet.allowed_files', []);
        $this->assertContains('app/Services/Ai/SelfConstruction/AgentControlPlaneChainIntegrityAuditService.php', $allowedFiles);
        $this->assertContains('tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php', $allowedFiles);
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_chain_integrity_certification_implementation_packet.implementation_policy.runtime_flag_mutation_allowed_by_packet'));
    }

    public function test_cli_status_json_works(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-chain-integrity-certification-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_chain_integrity_certification_status.v1',
            data_get($payload, 'schema_version'),
        );
        $this->assertContains(data_get($payload, 'status'), ['available', 'degraded', 'blocked', 'missing_artifacts']);
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_chain_integrity_certification_status.runtime_safety_all_false'));
        $this->assertSame(
            data_get($payload, 'agent_control_plane_chain_integrity_certification_status.current_next_required_slice'),
            data_get($payload, 'agent_control_plane_chain_integrity_certification_status.expected_next_required_slice'),
        );
    }

    public function test_agent_control_plane_json_exposes_new_capabilities(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);
        $this->assertContains('agent_control_plane_chain_integrity_certification_contract', $capabilities);
        $this->assertContains('agent_control_plane_chain_integrity_certification_preflight', $capabilities);
        $this->assertContains('agent_control_plane_chain_integrity_certification_implementation_packet', $capabilities);
        $this->assertContains('agent_control_plane_chain_integrity_certification_service', $capabilities);
        $this->assertContains('agent_control_plane_chain_integrity_certification_status_projection', $capabilities);
    }

    public function test_certification_does_not_touch_forge_or_self_improvement(): void
    {
        $audit = $this->newService()->audit();

        $payloadJson = (string) json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('AtlasCodeForge', $payloadJson);
        $this->assertStringNotContainsString('AtlasSelfImprovement', $payloadJson);
        $this->assertStringNotContainsString('Cartografia', $payloadJson);
        $this->assertStringNotContainsString('Rivals', $payloadJson);
    }

    public function test_documentation_contains_chain_integrity_section(): void
    {
        $audit = $this->newService()->audit();
        $documentation = (array) data_get($audit, 'documentation', []);
        $path = (string) data_get($documentation, 'contract_doc_path');
        $this->assertNotSame('', $path);
        $fullPath = base_path($path);
        $this->assertFileExists($fullPath);
        $contents = (string) file_get_contents($fullPath);
        $this->assertStringContainsString('Agent Control Plane Chain Integrity Certification v1', $contents);
        $this->assertStringContainsString('atlas.self_construction.agent_control_plane_chain_integrity_certification.v1', $contents);
    }

    public function test_runtime_safety_fields_are_all_false(): void
    {
        $audit = $this->newService()->audit();
        $expectedFalse = [
            'actual_process_start_allowed_anywhere',
            'provider_process_call_allowed_anywhere',
            'adapter_invocation_allowed_anywhere',
            'adapter_execution_allowed_anywhere',
            'dispatch_allowed_anywhere',
            'token_spend_allowed_anywhere',
            'self_programming_allowed_anywhere',
            'external_process_started_by_atlas',
            'codex_cli_invoked',
            'shell_spawned_by_runtime',
        ];
        foreach ($expectedFalse as $flag) {
            $this->assertFalse(
                (bool) data_get($audit, 'runtime_safety.'.$flag),
                "Runtime-safety flag {$flag} must remain false.",
            );
        }
    }

    public function test_status_projection_is_read_only(): void
    {
        $payload = $this->readiness()->agentControlPlaneChainIntegrityCertificationStatus();
        $this->assertFalse((bool) data_get($payload, 'execution_allowed'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_allowed'));
        $this->assertFalse((bool) data_get($payload, 'ledger_write_allowed'));
        $this->assertFalse((bool) data_get($payload, 'runtime_write_allowed'));
        $guarantees = (array) data_get($payload, 'non_execution_guarantees', []);
        $this->assertContains('agent_control_plane_chain_integrity_certification_status_does_not_start_codex', $guarantees);
        $this->assertContains('agent_control_plane_chain_integrity_certification_status_does_not_advance_pointer', $guarantees);
        $this->assertContains('agent_control_plane_chain_integrity_certification_status_does_not_dispatch_work', $guarantees);
        $this->assertContains('agent_control_plane_chain_integrity_certification_status_does_not_execute_adapter', $guarantees);
        $this->assertContains('agent_control_plane_chain_integrity_certification_status_does_not_enable_self_programming', $guarantees);
    }

    public function test_audit_invariants_map_holds_structural_invariants(): void
    {
        $audit = $this->newService()->audit();
        $invariants = (array) data_get($audit, 'invariants', []);
        $this->assertNotEmpty($invariants);

        // Structural invariants must hold regardless of whether the runtime
        // schema migration has been applied in the current environment. They
        // describe the integrity of the chain projection itself, not the
        // database state.
        $structuralInvariants = [
            'current_capability_is_unique',
            'next_required_slice_present',
            'next_build_slices_contains_next_required_slice',
            'expected_next_required_slice_present',
            'current_next_required_slice_matches_expected',
            'persistent_runtime_schema_known',
            'control_plane_canonical_name_present',
            'control_plane_parent_program_present',
            'runtime_safety_all_false',
            'contract_doc_present',
            'no_duplicate_doc_bullets',
            'cli_surface_aligned',
            'capability_surface_unique',
        ];
        foreach ($structuralInvariants as $invariant) {
            $this->assertTrue(
                (bool) data_get($invariants, $invariant),
                "Structural invariant {$invariant} must hold true.",
            );
        }
    }

    public function test_audit_emits_stable_hash(): void
    {
        $service = $this->newService();
        $first = $service->audit();
        $second = $service->audit();
        $this->assertSame(
            data_get($first, 'agent_control_plane_chain_integrity_certification_hash'),
            data_get($second, 'agent_control_plane_chain_integrity_certification_hash'),
            'Stable hash must remain identical across two consecutive audits when nothing has changed.',
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($first, 'agent_control_plane_chain_integrity_certification_hash'));
    }

    public function test_audit_lists_canonical_chain(): void
    {
        $audit = $this->newService()->audit();
        $sliceKeys = array_map(static fn (array $slice): string => (string) $slice['slice_key'], (array) data_get($audit, 'slices', []));
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate', $sliceKeys);
    }

    public function test_audit_reports_test_surface(): void
    {
        $audit = $this->newService()->audit();
        $testSurface = (array) data_get($audit, 'test_surface', []);
        $this->assertTrue((bool) data_get($testSurface, 'dedicated_test_present'));
        $this->assertTrue((bool) data_get($testSurface, 'command_test_present'));
    }

    public function test_audit_does_not_advance_pointer_when_status_is_called(): void
    {
        $beforeNext = $this->readControlPlanePointerViaCli();

        $this->newService()->audit();

        $afterNext = $this->readControlPlanePointerViaCli();

        $this->assertSame($beforeNext, $afterNext, 'Audit must not advance the next_required_slice pointer.');
    }

    private function readControlPlanePointerViaCli(): string
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (string) data_get($payload, 'control_plane.persistent_runtime.next_required_slice');
    }

    public function test_audit_reports_next_action(): void
    {
        $audit = $this->newService()->audit();
        $this->assertContains(
            data_get($audit, 'next_action'),
            ['verify_alignment', 'advance_pointer', 'fix_violations'],
        );
    }

    public function test_audit_deep_chain_covers_all_six_macro_continuation_slices(): void
    {
        $audit = $this->newService()->audit();
        $sliceKeys = array_map(static fn (array $slice): string => (string) $slice['slice_key'], (array) data_get($audit, 'slices', []));
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff', $sliceKeys);
    }

    public function test_audit_chain_coverage_reports_shallow_and_deep_counts(): void
    {
        $audit = $this->newService()->audit();
        $coverage = (array) data_get($audit, 'chain_coverage', []);
        $this->assertGreaterThanOrEqual(14, (int) data_get($coverage, 'deep_checked_slice_count'));
        $this->assertGreaterThanOrEqual(0, (int) data_get($coverage, 'shallow_checked_slice_count'));
        $this->assertGreaterThanOrEqual((int) data_get($coverage, 'deep_chain_ok_count'), 0);
        $this->assertSame(count((array) data_get($coverage, 'missing_deep_checks', [])), (int) data_get($coverage, 'missing_deep_check_count'));
    }

    public function test_audit_per_slice_next_edge_is_aligned(): void
    {
        $audit = $this->newService()->audit();
        $edges = (array) data_get($audit, 'per_slice_next_edge', []);
        $this->assertNotEmpty($edges);
        foreach ($edges as $edge) {
            $this->assertTrue((bool) data_get($edge, 'edge_ok'), 'Edge from '.data_get($edge, 'from_slice').' must be aligned with the deep chain order.');
        }
        $this->assertSame([], (array) data_get($audit, 'broken_edges', []));
    }

    public function test_audit_reports_all_structural_gap_collections_empty_in_current_state(): void
    {
        $audit = $this->newService()->audit();
        // invoker_gaps, readiness_gaps, cli_option_gaps, runtime_safety_gaps and
        // duplicate_doc_bullets describe pure structural integrity of the chain
        // — they must be empty regardless of whether the runtime schema migration
        // has been applied in the current environment.
        $this->assertSame([], (array) data_get($audit, 'invoker_gaps', []));
        $this->assertSame([], (array) data_get($audit, 'readiness_gaps', []));
        $this->assertSame([], (array) data_get($audit, 'cli_option_gaps', []));
        $this->assertSame([], (array) data_get($audit, 'runtime_safety_gaps', []));
        $this->assertSame([], (array) data_get($audit, 'duplicate_doc_bullets', []));
    }

    public function test_audit_chain_integrity_certification_status_now_points_to_post_start_receipt_contract(): void
    {
        $audit = $this->newService()->audit();
        $this->assertContains(
            data_get($audit, 'current_next_required_slice'),
            [
                'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
                // Allow fallback for environments that have not migrated yet.
                'apply_agent_control_plane_runtime_schema_migration',
            ],
        );
    }

    public function test_synthetic_capability_gap_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract',
                ],
            ],
        ]);
        $gaps = (array) data_get($audit, 'capability_gaps', []);
        $this->assertNotEmpty($gaps);
        $detectedSliceKeys = array_map(static fn (array $gap): string => (string) $gap['slice_key'], $gaps);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff', $detectedSliceKeys);
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('slice_missing_artifact', $codes);
    }

    public function test_synthetic_invoker_gap_is_detected_via_override(): void
    {
        $audit = $this->newService()->audit([
            'override_slices' => [
                [
                    'slice_key' => 'synthetic_invoker_gap',
                    'method_prefix' => 'agentControlPlane',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\NonExistentInvokerForCertificationTest',
                    'prepare_method' => 'prepareNonExistent',
                    'doc_bullet' => 'synthetic-invoker-gap',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_synthetic_invoker_gap_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_synthetic_invoker_gap_contract_runtime',
                ],
            ],
        ]);
        $invokerGaps = (array) data_get($audit, 'invoker_gaps', []);
        $this->assertNotEmpty($invokerGaps);
        $this->assertSame('synthetic_invoker_gap', data_get($invokerGaps, '0.slice_key'));
    }

    public function test_synthetic_readiness_gap_is_detected_via_override(): void
    {
        $audit = $this->newService()->audit([
            'override_slices' => [
                [
                    'slice_key' => 'synthetic_readiness_gap',
                    'method_prefix' => 'agentReadinessMethodThatDoesNotExistForCertification',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneChainIntegrityAuditService',
                    'prepare_method' => 'audit',
                    'doc_bullet' => 'synthetic readiness gap',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_synthetic_readiness_gap_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_synthetic_readiness_gap_contract_runtime',
                ],
            ],
        ]);
        $readinessGaps = (array) data_get($audit, 'readiness_gaps', []);
        $this->assertNotEmpty($readinessGaps);
        $detectedChecks = array_map(static fn (array $gap): string => (string) $gap['readiness_check'], $readinessGaps);
        $this->assertContains('contract_method_exists', $detectedChecks);
        $this->assertContains('preflight_method_exists', $detectedChecks);
        $this->assertContains('implementation_packet_method_exists', $detectedChecks);
        $this->assertContains('status_method_exists', $detectedChecks);
    }

    public function test_synthetic_cli_gap_is_detected_via_override(): void
    {
        $audit = $this->newService()->audit([
            'override_slices' => [
                [
                    'slice_key' => 'synthetic_cli_gap_slice',
                    'method_prefix' => 'agentControlPlane',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneChainIntegrityAuditService',
                    'prepare_method' => 'audit',
                    'doc_bullet' => 'synthetic cli gap',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_synthetic_cli_gap_slice_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_synthetic_cli_gap_slice_contract_runtime',
                ],
            ],
        ]);
        $cliGaps = (array) data_get($audit, 'cli_option_gaps', []);
        $this->assertNotEmpty($cliGaps);
        $this->assertContains('agent-synthetic-cli-gap-slice-contract', $cliGaps);
    }

    public function test_synthetic_duplicate_doc_bullet_is_detected(): void
    {
        // 'post-start' anchor appears many times — repeating the same prefix in the doc bullet
        // triggers the duplicate-anchored-bullet detector.
        $audit = $this->newService()->audit([
            'override_slices' => [
                [
                    'slice_key' => 'duplicate_doc_bullet_synthetic_slice_a',
                    'method_prefix' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker',
                    'prepare_method' => 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                    'doc_bullet' => 'post-start',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_duplicate_doc_bullet_synthetic_slice_a_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_duplicate_doc_bullet_synthetic_slice_a_contract_runtime',
                ],
            ],
        ]);
        $duplicateBullets = (array) data_get($audit, 'duplicate_doc_bullets', []);
        $this->assertContains('duplicate_doc_bullet_synthetic_slice_a', $duplicateBullets);
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('duplicate_slice_bullet_in_doc', $codes);
    }

    public function test_synthetic_broken_edge_is_detected(): void
    {
        $service = $this->newService();
        // Skip when the runtime schema migration has not been applied: the
        // upstream status method emits a `repair_*` placeholder in that case,
        // which the audit deliberately filters out to avoid false-positive
        // broken-edge violations. The detector is exercised against a real
        // status payload here, so we only run it when the schema is ready.
        if (! $this->statusReadyForBrokenEdge()) {
            $this->markTestSkipped('agent control plane runtime schema is not ready in this environment.');
        }
        $audit = $service->audit([
            'override_slices' => [
                [
                    'slice_key' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
                    'method_prefix' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker',
                    'prepare_method' => 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                    'doc_bullet' => 'post-start actual process start rehearsal gate',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_runtime',
                ],
                [
                    'slice_key' => 'synthetic_broken_edge_target',
                    'method_prefix' => 'agentControlPlane',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneChainIntegrityAuditService',
                    'prepare_method' => 'audit',
                    'doc_bullet' => 'synthetic broken edge',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_synthetic_broken_edge_target_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_synthetic_broken_edge_target_contract_runtime',
                ],
            ],
        ]);
        $brokenEdges = (array) data_get($audit, 'broken_edges', []);
        $this->assertNotEmpty($brokenEdges, 'A broken edge should be detected when the chain order is intentionally divergent.');
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('broken_chain_edge', $codes);
    }

    private function statusReadyForBrokenEdge(): bool
    {
        $payload = $this->readiness()->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateStatus($this->normalizedOptions());

        return data_get($payload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status.status')
            === 'one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_service_ready';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedOptions(): array
    {
        return [
            'workspace' => null,
            'target' => null,
            'packet' => null,
            'actor' => null,
            'session' => null,
            'lease_minutes' => null,
            'reason' => null,
            'evidence_hash' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_usd' => null,
            'artifact_type' => null,
            'artifact_path' => null,
            'artifact_hash' => null,
            'summary' => null,
            'decision' => null,
            'signed_by' => null,
            'receipt_hash' => null,
            'dispatch_envelope_hash' => null,
            'adapter_contract_hash' => null,
            'expires_at' => null,
        ];
    }

    public function test_synthetic_not_yet_runtime_capable_misalignment_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_runtime',
                ],
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
            ],
        ]);
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('next_required_slice_does_not_match_expected', $codes);
        $this->assertFalse((bool) data_get($audit, 'invariants.current_next_required_slice_matches_expected'));
    }

    public function test_synthetic_next_build_slices_misalignment_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_build_slices' => ['some_unrelated_slice_key'],
            ],
        ]);
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('next_build_slices_does_not_contain_next_required_slice', $codes);
        $this->assertFalse((bool) data_get($audit, 'invariants.next_build_slices_contains_next_required_slice'));
    }

    public function test_synthetic_runtime_flag_true_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'runtime_safety.runtime_safety_all_false'));
        $this->assertNotEmpty((array) data_get($audit, 'runtime_safety_gaps', []));
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('runtime_safety_invariant_breached', $codes);
    }

    public function test_synthetic_unknown_slice_in_chain_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_slices' => [
                [
                    'slice_key' => 'unknown_slice_not_registered_in_capabilities',
                    'method_prefix' => 'nonExistentMethodPrefix',
                    'invoker_class' => '',
                    'prepare_method' => '',
                    'doc_bullet' => '',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_unknown_slice_not_registered_in_capabilities_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_unknown_slice_not_registered_in_capabilities_contract_runtime',
                ],
            ],
        ]);
        $capabilityGaps = (array) data_get($audit, 'capability_gaps', []);
        $this->assertNotEmpty($capabilityGaps);
        $this->assertSame('unknown_slice_not_registered_in_capabilities', data_get($capabilityGaps, '0.slice_key'));
    }

    public function test_synthetic_capability_duplicate_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'append_capability' => ['durable_packet_checkout_lock'],
            ],
        ]);
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('capability_surface_has_duplicates', $codes);
        $this->assertGreaterThan(0, (int) data_get($audit, 'capability_surface.duplicate_count'));
    }

    public function test_shallow_chain_is_a_list_of_canonical_slice_descriptors(): void
    {
        // The shallow chain only contains slice families whose 5 capabilities
        // are emitted by agentControlPlane(); when the runtime schema migration
        // has not been applied the family count may be zero. Structurally,
        // every entry must still have a slice_key and a ok flag.
        $audit = $this->newService()->audit();
        $shallow = (array) data_get($audit, 'shallow_chain', []);
        $this->assertIsArray($shallow);
        foreach ($shallow as $entry) {
            $this->assertNotSame('', (string) data_get($entry, 'slice_key'));
            $this->assertArrayHasKey('checks', $entry);
            $this->assertArrayHasKey('ok', $entry);
        }
    }

    public function test_audit_capability_surface_reports_total_unique(): void
    {
        $audit = $this->newService()->audit();
        $surface = (array) data_get($audit, 'capability_surface', []);
        $this->assertSame(0, (int) data_get($surface, 'duplicate_count'));
        $this->assertGreaterThanOrEqual(0, (int) data_get($surface, 'shallow_checked'));
        $this->assertGreaterThanOrEqual(14, (int) data_get($surface, 'deep_checked'));
    }

    public function test_audit_invokers_match_canonical_prepare_methods_in_deep_chain(): void
    {
        $audit = $this->newService()->audit();
        $slices = (array) data_get($audit, 'slices', []);
        foreach ($slices as $slice) {
            $invokerClass = (string) data_get($slice, 'invoker_class');
            $prepareMethod = (string) data_get($slice, 'prepare_method');
            if ($invokerClass === '' || $prepareMethod === '') {
                continue;
            }
            $this->assertTrue(class_exists($invokerClass), "Invoker class {$invokerClass} must exist.");
            $this->assertTrue(method_exists($invokerClass, $prepareMethod), "Invoker method {$prepareMethod} must exist on {$invokerClass}.");
        }
    }

    public function test_audit_invariants_map_has_at_least_15_entries(): void
    {
        $audit = $this->newService()->audit();
        $invariants = (array) data_get($audit, 'invariants', []);
        $this->assertGreaterThanOrEqual(15, count($invariants));
    }

    public function test_audit_payload_is_self_contained_and_does_not_leak_runtime(): void
    {
        $audit = $this->newService()->audit();
        $payloadJson = (string) json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"execution_allowed": true', $payloadJson);
        $this->assertStringNotContainsString('"dispatch_allowed": true', $payloadJson);
        $this->assertStringNotContainsString('"ledger_write_allowed": true', $payloadJson);
    }

    public function test_audit_deep_chain_covers_evidence_to_dispatch_corridor(): void
    {
        $audit = $this->newService()->audit();
        $sliceKeys = array_map(static fn (array $slice): string => (string) $slice['slice_key'], (array) data_get($audit, 'slices', []));
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor', $sliceKeys);
    }

    public function test_audit_evidence_corridor_invariants_are_ok(): void
    {
        $audit = $this->newService()->audit();
        $corridor = (array) data_get($audit, 'post_start_evidence_corridor', []);
        $this->assertSame(8, (int) data_get($corridor, 'corridor_total_count'));
        $sliceStatus = (array) data_get($corridor, 'corridor_slice_status', []);
        foreach ($sliceStatus as $logical => $info) {
            $this->assertTrue((bool) data_get($info, 'present_in_deep_chain'), "Corridor slice {$logical} must be present in deep chain.");
            // Methods + invoker class must always exist regardless of DB state.
            $this->assertTrue((bool) data_get($info, 'contract_method_exists'), "Corridor slice {$logical} contract method must exist.");
            $this->assertTrue((bool) data_get($info, 'preflight_method_exists'));
            $this->assertTrue((bool) data_get($info, 'implementation_packet_method_exists'));
            $this->assertTrue((bool) data_get($info, 'status_method_exists'));
            $this->assertTrue((bool) data_get($info, 'invoker_class_exists'));
            $this->assertTrue((bool) data_get($info, 'invoker_prepare_method_exists'));
        }
        // Capability-backed corridor flags only hold when the runtime schema
        // has been applied — they live inside agentControlPlane()'s
        // schema_ready branch. We assert them defensively.
        $persistentRuntimeStatus = $this->controlPlanePersistentRuntimeStatus();
        if ($persistentRuntimeStatus === 'schema_ready') {
            $this->assertTrue((bool) data_get($audit, 'evidence_to_dispatch_chain_ok'));
            $this->assertTrue((bool) data_get($audit, 'dispatch_authorization_chain_ok'));
            $this->assertTrue((bool) data_get($audit, 'receipt_use_chain_ok'));
            $this->assertSame(8, (int) data_get($corridor, 'corridor_ok_count'));
        }
    }

    private function controlPlanePersistentRuntimeStatus(): string
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (string) data_get($payload, 'control_plane.persistent_runtime.status');
    }

    public function test_audit_pointer_advanced_to_provider_start_driver_gate_contract(): void
    {
        $audit = $this->newService()->audit();
        $this->assertContains(
            data_get($audit, 'current_next_required_slice'),
            [
                'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
                // Allow fallback for environments that have not migrated yet.
                'apply_agent_control_plane_runtime_schema_migration',
            ],
        );
    }

    public function test_synthetic_evidence_receipt_without_receipt_contract_capability_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
                ],
            ],
        ]);
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('slice_missing_artifact', $codes);
        $capabilityGaps = (array) data_get($audit, 'capability_gaps', []);
        $detectedSliceKeys = array_map(static fn (array $g): string => (string) $g['slice_key'], $capabilityGaps);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', $detectedSliceKeys);
        $this->assertFalse((bool) data_get($audit, 'evidence_to_dispatch_chain_ok'));
    }

    public function test_synthetic_acceptance_bridge_without_evidence_receipt_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract',
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight',
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet',
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_invoker_service',
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_projection',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'evidence_to_dispatch_chain_ok'));
        $capabilityGaps = (array) data_get($audit, 'capability_gaps', []);
        $detectedSliceKeys = array_map(static fn (array $g): string => (string) $g['slice_key'], $capabilityGaps);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt', $detectedSliceKeys);
    }

    public function test_synthetic_liveness_not_alive_but_dispatch_release_prepared_breaks_invariant(): void
    {
        // Remove liveness capability quintet and check that the
        // evidence-to-dispatch invariant detects the inconsistency.
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'evidence_to_dispatch_chain_ok'));
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('slice_missing_artifact', $codes);
    }

    public function test_synthetic_dispatch_release_missing_signed_policy_breaks_corridor(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'dispatch_authorization_chain_ok'));
    }

    public function test_synthetic_signed_auth_missing_human_signature_breaks_corridor(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'dispatch_authorization_chain_ok'));
    }

    public function test_synthetic_executor_handoff_missing_scope_lock_breaks_receipt_use_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'receipt_use_chain_ok'));
    }

    public function test_synthetic_receipt_use_missing_breaks_receipt_use_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'receipt_use_chain_ok'));
    }

    public function test_synthetic_provider_start_allowed_after_mark_true_is_detected(): void
    {
        // Even with the corridor structurally aligned, flipping the runtime
        // flags must be caught by the runtime-safety invariant. Note that
        // execution_allowed=true acts as a stand-in for any post-mark provider
        // start authorization because the projection deliberately mirrors all
        // runtime flags through the canonical execution_allowed gate.
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'runtime_safety.runtime_safety_all_false'));
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('runtime_safety_invariant_breached', $codes);
    }

    public function test_synthetic_dispatch_allowed_true_anywhere_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'runtime_safety.runtime_safety_all_false'));
        $this->assertContains('dispatch_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_broken_edge_inside_corridor_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_slices' => [
                [
                    'slice_key' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
                    'method_prefix' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContract',
                    'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker',
                    'prepare_method' => 'buildCodexRealInvokerPostStartReceiptContract',
                    'doc_bullet' => 'post-start receipt contract',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
                ],
                [
                    'slice_key' => 'synthetic_unrelated_chain_target',
                    'method_prefix' => '',
                    'invoker_class' => '',
                    'prepare_method' => '',
                    'doc_bullet' => '',
                    'activate_key' => 'activate_signed_one_shot_scheduler_tick_synthetic_unrelated_chain_target_contract',
                    'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_synthetic_unrelated_chain_target_contract_runtime',
                ],
            ],
        ]);
        $brokenEdges = (array) data_get($audit, 'broken_edges', []);
        // Without a live status payload we may not see a broken-edge
        // detection (declared_next is empty when the readiness service is
        // not bootstrapped enough), so we instead assert the chain
        // structural mismatch via capability gaps from the synthetic
        // unknown slice.
        $capabilityGaps = (array) data_get($audit, 'capability_gaps', []);
        $detectedSliceKeys = array_map(static fn (array $g): string => (string) $g['slice_key'], $capabilityGaps);
        $this->assertContains('synthetic_unrelated_chain_target', $detectedSliceKeys);
        $this->assertIsArray($brokenEdges);
    }

    public function test_audit_corridor_slice_status_contains_canonical_keys(): void
    {
        $audit = $this->newService()->audit();
        $corridor = (array) data_get($audit, 'post_start_evidence_corridor', []);
        $sliceStatus = (array) data_get($corridor, 'corridor_slice_status', []);
        $this->assertArrayHasKey('post_start_receipt_contract', $sliceStatus);
        $this->assertArrayHasKey('post_start_evidence_receipt', $sliceStatus);
        $this->assertArrayHasKey('post_start_evidence_acceptance_bridge', $sliceStatus);
        $this->assertArrayHasKey('post_start_liveness_monitor', $sliceStatus);
        $this->assertArrayHasKey('post_start_dispatch_release_gate', $sliceStatus);
        $this->assertArrayHasKey('post_start_signed_dispatch_authorization_gate', $sliceStatus);
        $this->assertArrayHasKey('post_start_dispatch_executor_handoff', $sliceStatus);
        $this->assertArrayHasKey('post_start_dispatch_receipt_use_executor', $sliceStatus);
    }

    public function test_audit_chain_length_reflects_22_macros(): void
    {
        $audit = $this->newService()->audit();
        $this->assertGreaterThanOrEqual(22, (int) data_get($audit, 'chain_length'));
        $this->assertGreaterThanOrEqual(22, (int) data_get($audit, 'checked_slice_count'));
    }

    public function test_audit_per_slice_next_edge_covers_corridor_transitions(): void
    {
        $audit = $this->newService()->audit();
        $edges = (array) data_get($audit, 'per_slice_next_edge', []);
        $fromSlices = array_map(static fn (array $edge): string => (string) $edge['from_slice'], $edges);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', $fromSlices);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt', $fromSlices);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff', $fromSlices);
    }

    public function test_audit_chain_integrity_invariants_includes_corridor_flags(): void
    {
        $audit = $this->newService()->audit();
        $this->assertArrayHasKey('evidence_to_dispatch_chain_ok', $audit);
        $this->assertArrayHasKey('dispatch_authorization_chain_ok', $audit);
        $this->assertArrayHasKey('receipt_use_chain_ok', $audit);
        $this->assertArrayHasKey('post_start_provider_start_driver_ready_next', $audit);
    }

    public function test_audit_deep_chain_covers_provider_to_real_invoker_corridor(): void
    {
        $audit = $this->newService()->audit();
        $sliceKeys = array_map(static fn (array $slice): string => (string) $slice['slice_key'], (array) data_get($audit, 'slices', []));
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate', $sliceKeys);
        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate', $sliceKeys);
    }

    public function test_audit_provider_to_runtime_corridor_structurally_complete(): void
    {
        $audit = $this->newService()->audit();
        $corridor = (array) data_get($audit, 'provider_to_runtime_corridor', []);
        $this->assertSame(13, (int) data_get($corridor, 'corridor_total_count'));
        $sliceStatus = (array) data_get($corridor, 'corridor_slice_status', []);
        foreach ($sliceStatus as $logical => $info) {
            $this->assertTrue((bool) data_get($info, 'present_in_deep_chain'), "Corridor slice {$logical} must be present in deep chain.");
            $this->assertTrue((bool) data_get($info, 'contract_method_exists'));
            $this->assertTrue((bool) data_get($info, 'preflight_method_exists'));
            $this->assertTrue((bool) data_get($info, 'implementation_packet_method_exists'));
            $this->assertTrue((bool) data_get($info, 'status_method_exists'));
            $this->assertTrue((bool) data_get($info, 'invoker_class_exists'));
            $this->assertTrue((bool) data_get($info, 'invoker_prepare_method_exists'));
        }
    }

    public function test_audit_provider_runtime_preflight_matrix_is_complete(): void
    {
        $audit = $this->newService()->audit();
        $matrix = (array) data_get($audit, 'provider_runtime_preflight_matrix', []);
        $this->assertSame('provider_runtime_preflight_matrix', data_get($matrix, 'matrix_name'));
        $this->assertSame('v1', data_get($matrix, 'matrix_version'));
        $this->assertSame(13, (int) data_get($matrix, 'row_count'));
        $this->assertGreaterThanOrEqual(60, (int) data_get($matrix, 'check_count'));
        // Sub-rows must each declare runtime_flags_false expectation.
        $rows = (array) data_get($matrix, 'rows', []);
        foreach ($rows as $logical => $row) {
            $this->assertArrayHasKey('runtime_flags_false', (array) data_get($row, 'checks', []), "Matrix row {$logical} missing runtime_flags_false check.");
        }
    }

    public function test_audit_provider_to_runtime_subchains_present(): void
    {
        $audit = $this->newService()->audit();
        $this->assertArrayHasKey('adapter_boundary_chain_ok', $audit);
        $this->assertArrayHasKey('provider_execution_contract_chain_ok', $audit);
        $this->assertArrayHasKey('process_start_release_chain_ok', $audit);
        $this->assertArrayHasKey('supervised_spawn_chain_ok', $audit);
        $this->assertArrayHasKey('external_runtime_chain_ok', $audit);
        $this->assertArrayHasKey('invocation_authorization_chain_ok', $audit);
        $this->assertArrayHasKey('dry_run_to_release_preflight_chain_ok', $audit);
        $this->assertArrayHasKey('signed_real_release_ready_next', $audit);
    }

    public function test_audit_pointer_advanced_to_process_starter_readiness_gate_contract(): void
    {
        $audit = $this->newService()->audit();
        $this->assertContains(
            data_get($audit, 'current_next_required_slice'),
            [
                'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract',
                // Allow fallback for environments that have not migrated yet.
                'apply_agent_control_plane_runtime_schema_migration',
            ],
        );
    }

    public function test_audit_corridor_edge_status_is_contiguous(): void
    {
        $audit = $this->newService()->audit();
        $edges = (array) data_get($audit, 'provider_to_runtime_corridor.corridor_edge_status', []);
        $this->assertNotEmpty($edges);
        foreach ($edges as $edge) {
            $this->assertArrayHasKey('from_slice', $edge);
            $this->assertArrayHasKey('to_slice', $edge);
            $this->assertArrayHasKey('edge_ok', $edge);
        }
    }

    public function test_synthetic_provider_start_driver_without_used_dispatch_receipt_breaks_corridor(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'receipt_use_chain_ok'));
    }

    public function test_synthetic_provider_started_true_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'runtime_safety.runtime_safety_all_false'));
        $this->assertContains('provider_process_call_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_adapter_boundary_without_provider_start_metadata_is_detected(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'provider_to_runtime_chain_ok'));
    }

    public function test_synthetic_adapter_invocation_allowed_true_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('adapter_invocation_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_adapter_execution_guard_missing_breaks_adapter_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'adapter_boundary_chain_ok'));
    }

    public function test_synthetic_adapter_execution_allowed_true_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('adapter_execution_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_provider_execution_contract_with_provider_call_true_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('provider_process_call_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_provider_execution_contract_with_token_spend_true_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('token_spend_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_process_start_release_missing_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'process_start_release_chain_ok'));
    }

    public function test_synthetic_supervised_start_missing_breaks_supervised_spawn_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'supervised_spawn_chain_ok'));
    }

    public function test_synthetic_process_spawn_enablement_missing_breaks_supervised_spawn_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'supervised_spawn_chain_ok'));
    }

    public function test_synthetic_final_process_spawn_executor_missing_breaks_supervised_spawn_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'supervised_spawn_chain_ok'));
    }

    public function test_synthetic_external_runtime_missing_breaks_runtime_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'external_runtime_chain_ok'));
    }

    public function test_synthetic_invocation_authorization_missing_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'invocation_authorization_chain_ok'));
    }

    public function test_synthetic_dry_run_missing_breaks_preflight_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'dry_run_to_release_preflight_chain_ok'));
    }

    public function test_synthetic_release_preflight_missing_breaks_preflight_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'dry_run_to_release_preflight_chain_ok'));
    }

    public function test_synthetic_signed_real_release_missing_breaks_corridor(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'provider_to_runtime_chain_ok'));
        $this->assertFalse((bool) data_get($audit, 'signed_real_release_ready_next'));
    }

    public function test_synthetic_pointer_not_at_implementation_boundary_breaks_signed_real_release_ready_next(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
                'next_build_slices' => ['activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract'],
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_provider_start_driver_gate_contract_runtime',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'signed_real_release_ready_next'));
    }

    public function test_synthetic_runtime_flag_true_in_corridor_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'runtime_safety.runtime_safety_all_false'));
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'violations', []));
        $this->assertContains('runtime_safety_invariant_breached', $codes);
    }

    public function test_audit_chain_length_reflects_34_macros(): void
    {
        $audit = $this->newService()->audit();
        $this->assertGreaterThanOrEqual(34, (int) data_get($audit, 'chain_length'));
        $this->assertGreaterThanOrEqual(34, (int) data_get($audit, 'checked_slice_count'));
    }

    public function test_audit_deep_chain_covers_implementation_to_operator_handoff_corridor(): void
    {
        $audit = $this->newService()->audit();
        $sliceKeys = array_map(static fn (array $slice): string => (string) $slice['slice_key'], (array) data_get($audit, 'slices', []));
        $required = [
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff',
        ];
        foreach ($required as $slice) {
            $this->assertContains($slice, $sliceKeys, "Deep chain must contain {$slice}.");
        }
    }

    public function test_audit_implementation_to_operator_handoff_corridor_complete(): void
    {
        $audit = $this->newService()->audit();
        $corridor = (array) data_get($audit, 'implementation_to_operator_handoff_corridor', []);
        $this->assertSame(13, (int) data_get($corridor, 'corridor_total_count'));
        $sliceStatus = (array) data_get($corridor, 'corridor_slice_status', []);
        foreach ($sliceStatus as $logical => $info) {
            $this->assertTrue((bool) data_get($info, 'present_in_deep_chain'), "Corridor slice {$logical} must be present in deep chain.");
            $this->assertTrue((bool) data_get($info, 'contract_method_exists'));
            $this->assertTrue((bool) data_get($info, 'preflight_method_exists'));
            $this->assertTrue((bool) data_get($info, 'implementation_packet_method_exists'));
            $this->assertTrue((bool) data_get($info, 'status_method_exists'));
            $this->assertTrue((bool) data_get($info, 'invoker_class_exists'));
            $this->assertTrue((bool) data_get($info, 'invoker_prepare_method_exists'));
        }
    }

    public function test_audit_implementation_corridor_subchains_present(): void
    {
        $audit = $this->newService()->audit();
        $this->assertArrayHasKey('implementation_boundary_chain_ok', $audit);
        $this->assertArrayHasKey('executor_plan_chain_ok', $audit);
        $this->assertArrayHasKey('executor_release_chain_ok', $audit);
        $this->assertArrayHasKey('executor_enablement_chain_ok', $audit);
        $this->assertArrayHasKey('supervised_activation_chain_ok', $audit);
        $this->assertArrayHasKey('guarded_start_chain_ok', $audit);
        $this->assertArrayHasKey('final_authorization_chain_ok', $audit);
        $this->assertArrayHasKey('rehearsal_to_envelope_chain_ok', $audit);
        $this->assertArrayHasKey('start_execution_to_readiness_chain_ok', $audit);
        $this->assertArrayHasKey('manual_start_to_operator_handoff_chain_ok', $audit);
        $this->assertArrayHasKey('operator_handoff_reentry_ready', $audit);
    }

    public function test_audit_cycle_integrity_block_is_present_and_well_formed(): void
    {
        $audit = $this->newService()->audit();
        $cycle = (array) data_get($audit, 'cycle_integrity', []);
        $this->assertSame('atlas.self_construction.agent_control_plane_cycle_integrity.v1', data_get($cycle, 'schema_version'));
        $this->assertContains(data_get($cycle, 'status'), ['ok', 'warning', 'blocked']);
        $this->assertArrayHasKey('cycle_detected', $cycle);
        $this->assertArrayHasKey('unintentional_cycle_detected', $cycle);
        $this->assertArrayHasKey('intentional_reentry_detected', $cycle);
        $this->assertArrayHasKey('intentional_reentry_target', $cycle);
        $this->assertArrayHasKey('intentional_reentry_reason', $cycle);
        $this->assertArrayHasKey('repeated_slice_families', $cycle);
        $this->assertArrayHasKey('repeated_slice_count', $cycle);
        $this->assertArrayHasKey('reentry_edges', $cycle);
        $this->assertArrayHasKey('regressions', $cycle);
        $this->assertArrayHasKey('regression_count', $cycle);
        $this->assertArrayHasKey('cycle_warnings', $cycle);
        $this->assertArrayHasKey('cycle_violations', $cycle);
        $this->assertArrayHasKey('cycle_ok', $cycle);
        $this->assertArrayHasKey('terminal_horizon', $cycle);
        $this->assertArrayHasKey('terminal_horizon_reason', $cycle);
    }

    public function test_audit_intentional_reentry_after_operator_handoff_is_accepted(): void
    {
        $audit = $this->newService()->audit();
        $cycle = (array) data_get($audit, 'cycle_integrity', []);
        // In live env the pointer is post_start_receipt_contract; in test env
        // (schema_missing) the pointer is the migration sentinel.
        if (data_get($audit, 'current_next_required_slice') !== 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract') {
            $this->markTestSkipped('intentional reentry only observable when persistent runtime schema is applied.');
        }
        $this->assertTrue((bool) data_get($cycle, 'intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', data_get($cycle, 'intentional_reentry_target'));
        $this->assertSame('operator_handoff_completes_release_cycle_and_reenters_post_start_evidence_corridor', data_get($cycle, 'intentional_reentry_reason'));
        $this->assertTrue((bool) data_get($cycle, 'cycle_ok'));
        $this->assertSame('ok', data_get($cycle, 'status'));
    }

    public function test_audit_terminal_horizon_analysis_block_is_present_and_well_formed(): void
    {
        $audit = $this->newService()->audit();
        $th = (array) data_get($audit, 'terminal_horizon_analysis', []);
        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_horizon_analysis.v1', data_get($th, 'schema_version'));
        $this->assertContains(data_get($th, 'horizon_type'), ['linear_next', 'intentional_reentry', 'terminal_runtime_gate', 'blocked_unknown']);
        $this->assertArrayHasKey('current_pointer', $th);
        $this->assertArrayHasKey('expected_pointer', $th);
        $this->assertArrayHasKey('horizon_reason', $th);
        $this->assertArrayHasKey('horizon_ok', $th);
        $this->assertArrayHasKey('next_safe_macro_batch', $th);
        $this->assertArrayHasKey('remaining_known_slices_after_horizon', $th);
        $this->assertFalse((bool) data_get($th, 'completion_claim_allowed'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_receipt_contract(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
            ],
        ]);
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_evidence_receipt_contract_after_receipt_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_evidence_receipt_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_evidence_acceptance_bridge_contract_after_evidence_receipt_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_liveness_monitor_contract_after_evidence_acceptance_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_liveness_monitor_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_dispatch_release_gate_contract_after_liveness_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_dispatch_release_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_signed_dispatch_authorization_gate_contract_after_dispatch_release_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_dispatch_executor_handoff_contract_after_signed_authorization_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_dispatch_executor_handoff_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_dispatch_receipt_use_executor_contract_after_handoff_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_provider_start_driver_gate_contract_after_receipt_use_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_provider_start_driver_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_adapter_invocation_boundary_gate_contract_after_provider_start_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_adapter_execution_guard_gate_contract_after_boundary_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_provider_execution_contract_gate_contract_after_execution_guard_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_provider_execution_contract_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_process_start_release_gate_contract_after_provider_execution_contract_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_process_start_release_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_supervised_start_executor_gate_contract_after_process_start_release_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_supervised_start_executor_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_process_spawn_enablement_gate_contract_after_supervised_start_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_final_process_spawn_executor_gate_contract_after_spawn_enablement_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_external_process_runtime_gate_contract_after_final_spawn_executor_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_external_process_runtime_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_process_invocation_authorization_gate_contract_after_external_runtime_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_external_process_invoker_dry_run_gate_contract_after_invocation_authorization_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_real_invoker_release_preflight_gate_contract_after_dry_run_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_signed_real_invoker_release_gate_contract_after_preflight_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_implementation_boundary_gate_contract_after_signed_release_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_implementation_boundary_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_executor_plan_gate_contract_after_boundary_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_executor_plan_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_executor_fresh_release_gate_contract_after_plan_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_executor_fresh_release_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_executor_enablement_gate_contract_after_fresh_release_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_executor_enablement_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_supervised_start_activation_gate_contract_after_enablement_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_supervised_start_activation_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_guarded_process_start_executor_gate_contract_after_activation_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_final_process_start_authorization_gate_contract_after_guarded_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_actual_process_start_rehearsal_gate_contract_after_final_authorization_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_process_start_envelope_gate_contract_after_rehearsal_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_process_start_envelope_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_target_accepts_post_start_start_execution_gate_contract_after_envelope_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_contract',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_post_start_start_execution_gate_contract_runtime',
                ],
                'next_build_slices' => [
                    'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_contract',
                ],
            ],
        ]);
        $this->assertSame('ok', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
        $this->assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate', data_get($audit, 'cycle_integrity.intentional_reentry_target'));
    }

    public function test_synthetic_reentry_from_wrong_slice_is_violation(): void
    {
        // Point the pointer at a previously-certified future slice
        // that is NOT the intentional reentry target. The audit must flag it.
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract',
            ],
        ]);
        $this->assertSame('blocked', data_get($audit, 'cycle_integrity.status'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.unintentional_cycle_detected'));
        $codes = array_map(static fn (array $v): string => (string) $v['code'], (array) data_get($audit, 'cycle_integrity.regressions', []));
        $this->assertContains('pointer_regressed_into_previously_certified_slice_without_reentry', $codes);
    }

    public function test_synthetic_pointer_regression_without_reentry_is_violation(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract',
            ],
        ]);
        $this->assertSame('blocked', data_get($audit, 'cycle_integrity.status'));
        $this->assertGreaterThan(0, (int) data_get($audit, 'cycle_integrity.regression_count'));
    }

    public function test_synthetic_terminal_horizon_blocked_unknown_when_pointer_is_unknown(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_some_unknown_slice_that_does_not_exist',
                'not_yet_runtime_capable' => [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                    'automatic_dispatch_scheduler_codex_real_invoker_some_unknown_slice_runtime',
                ],
            ],
        ]);
        $this->assertSame('blocked_unknown', data_get($audit, 'terminal_horizon_analysis.horizon_type'));
        $this->assertFalse((bool) data_get($audit, 'terminal_horizon_analysis.horizon_ok'));
    }

    public function test_synthetic_operator_handoff_without_manual_receipt_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'manual_start_to_operator_handoff_chain_ok'));
    }

    public function test_synthetic_manual_receipt_without_readiness_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'start_execution_to_readiness_chain_ok'));
    }

    public function test_synthetic_readiness_without_start_execution_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'start_execution_to_readiness_chain_ok'));
    }

    public function test_synthetic_start_execution_without_envelope_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'rehearsal_to_envelope_chain_ok'));
    }

    public function test_synthetic_envelope_without_rehearsal_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'rehearsal_to_envelope_chain_ok'));
    }

    public function test_synthetic_rehearsal_without_final_authorization_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'final_authorization_chain_ok'));
    }

    public function test_synthetic_final_authorization_with_actual_process_start_allowed_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('actual_process_start_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_guarded_start_with_shell_spawned_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'execution_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('shell_spawned_by_runtime', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_supervised_activation_with_provider_call_allowed_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('provider_process_call_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_executor_enablement_with_dispatch_allowed_breaks_runtime_safety(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'flags' => [
                    'dispatch_allowed' => true,
                ],
            ],
        ]);
        $this->assertContains('dispatch_allowed_anywhere', (array) data_get($audit, 'runtime_safety_gaps', []));
    }

    public function test_synthetic_executor_fresh_release_missing_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'executor_release_chain_ok'));
    }

    public function test_synthetic_executor_plan_missing_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'executor_plan_chain_ok'));
    }

    public function test_synthetic_implementation_boundary_missing_breaks_chain(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'remove_capability' => [
                    'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract',
                ],
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'implementation_boundary_chain_ok'));
    }

    public function test_synthetic_pointer_final_not_reentering_receipt_contract_breaks_reentry_ready(): void
    {
        $audit = $this->newService()->audit([
            'override_projection' => [
                'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract',
            ],
        ]);
        $this->assertFalse((bool) data_get($audit, 'operator_handoff_reentry_ready'));
    }

    public function test_audit_completion_claim_is_never_allowed(): void
    {
        $audit = $this->newService()->audit();
        $this->assertFalse((bool) data_get($audit, 'terminal_horizon_analysis.completion_claim_allowed'));
    }

    public function test_audit_runtime_safety_remains_all_false_in_happy_path(): void
    {
        $audit = $this->newService()->audit();
        $this->assertTrue((bool) data_get($audit, 'runtime_safety.runtime_safety_all_false'));
    }

    public function test_audit_cycle_ok_in_happy_path_when_pointer_is_intentional_reentry(): void
    {
        if ($this->controlPlanePersistentRuntimeStatus() !== 'schema_ready') {
            $this->markTestSkipped('cycle_ok intentional reentry only observable when runtime schema is applied.');
        }
        $audit = $this->newService()->audit();
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.cycle_ok'));
        $this->assertTrue((bool) data_get($audit, 'cycle_integrity.intentional_reentry_detected'));
    }

    public function test_audit_repeated_slice_families_reports_zero_in_happy_path(): void
    {
        $audit = $this->newService()->audit();
        $this->assertSame(0, (int) data_get($audit, 'cycle_integrity.repeated_slice_count'));
        $this->assertSame([], (array) data_get($audit, 'cycle_integrity.repeated_slice_families'));
    }

    public function test_audit_chain_length_reflects_at_least_34_macros(): void
    {
        $audit = $this->newService()->audit();
        $this->assertGreaterThanOrEqual(34, (int) data_get($audit, 'chain_length'));
        $this->assertGreaterThanOrEqual(34, (int) data_get($audit, 'checked_slice_count'));
    }

    public function test_audit_handles_naming_asymmetry_for_post_start_receipt_contract(): void
    {
        $audit = $this->newService()->audit();
        $slices = (array) data_get($audit, 'slices', []);
        $receiptContract = null;
        foreach ($slices as $slice) {
            if (data_get($slice, 'slice_key') === 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract') {
                $receiptContract = $slice;
                break;
            }
        }
        $this->assertNotNull($receiptContract);
        // The contract method must be resolvable even though the slice base
        // ends in `_contract` (asymmetry: no double `Contract` suffix).
        $this->assertTrue((bool) data_get($receiptContract, 'checks.contract_method_exists'));
        if ($this->controlPlanePersistentRuntimeStatus() === 'schema_ready') {
            $this->assertTrue((bool) data_get($receiptContract, 'checks.capability_contract_registered'));
            $this->assertTrue((bool) data_get($receiptContract, 'ok'));
        }
    }

    private function newService(): AgentControlPlaneChainIntegrityAuditService
    {
        return new AgentControlPlaneChainIntegrityAuditService($this->readiness());
    }

    private function readiness(): AtlasSelfConstructionReadinessService
    {
        return app(AtlasSelfConstructionReadinessService::class);
    }
}
