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

        // The test DB may run in schema_missing mode (~15 capabilities) or in
        // schema_ready mode (300+). Either way the surface must be populated,
        // unique, and exposed at least as many slices as the deep chain.
        $this->assertGreaterThanOrEqual(
            (int) data_get($capabilitySurface, 'deep_checked'),
            (int) data_get($capabilitySurface, 'total'),
        );
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

    private function newService(): AgentControlPlaneChainIntegrityAuditService
    {
        return new AgentControlPlaneChainIntegrityAuditService($this->readiness());
    }

    private function readiness(): AtlasSelfConstructionReadinessService
    {
        return app(AtlasSelfConstructionReadinessService::class);
    }
}
