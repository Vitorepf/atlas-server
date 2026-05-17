<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalCompletionReadinessGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use App\Services\Ai\SelfConstruction\AtlasSelfProgrammingSafetyContractCertificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionFinalCompletionReadinessGateTest extends TestCase
{
    public function test_gate_is_incomplete_in_current_real_state(): void
    {
        $gate = $this->gate()->evaluate();

        $this->assertSame('atlas.self_construction.final_completion_readiness_gate.v1', $gate['schema_version']);
        $this->assertSame('incomplete', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
        $this->assertSame('', (string) $gate['next_stage_name']);
        $this->assertSame('blocked', (string) $gate['self_programming_os_transition_status']);
        $this->assertContains('self_construction_os_not_complete', (array) $gate['self_programming_os_transition_blockers']);
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.runtime_activation_allowed'));
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.self_programming_allowed'));
        $this->assertGreaterThan(0, (int) $gate['blocker_count']);
        $this->assertContains('final_completion_readiness_gate_does_not_promote_completion', (array) $gate['non_execution_guarantees']);
    }

    public function test_gate_returns_complete_candidate_when_only_human_receipt_missing(): void
    {
        $audit = $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('complete_candidate', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertContains('human_signed_os_complete_receipt_present', (array) $gate['blockers']);
    }

    public function test_gate_returns_incomplete_when_runtime_or_smoke_missing(): void
    {
        $auditRuntimeMissing = $this->auditAllPassedExcept(['runtime_gap_matrix_all_runtime_y', 'human_signed_os_complete_receipt_present']);
        $gateRuntime = $this->gate()->evaluate(['completion_audit' => $auditRuntimeMissing]);
        $this->assertSame('incomplete', $gateRuntime['status']);
        $this->assertFalse((bool) $gateRuntime['runtime_green']);

        $auditSmokeMissing = $this->auditAllPassedExcept(['end_to_end_real_provider_smoke_green', 'human_signed_os_complete_receipt_present']);
        $gateSmoke = $this->gate()->evaluate(['completion_audit' => $auditSmokeMissing]);
        $this->assertSame('incomplete', $gateSmoke['status']);
        $this->assertFalse((bool) $gateSmoke['smoke_green']);
    }

    public function test_gate_returns_complete_only_when_audit_complete_and_all_criteria_passed(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('complete', $gate['status']);
        $this->assertTrue((bool) $gate['completion_allowed']);
        $this->assertTrue((bool) $gate['completion_claim_allowed']);
        $this->assertTrue((bool) $gate['next_stage_allowed']);
        $this->assertTrue((bool) $gate['material_completion_evidence_green']);
        $this->assertTrue((bool) $gate['terminal_loop_operational_proof_green']);
        $this->assertSame('Atlas Self-Programming OS', (string) $gate['next_stage_name']);
        $this->assertSame([], (array) $gate['next_stage_blocked_by']);
        $this->assertSame('ready_for_safety_contract_design', (string) $gate['self_programming_os_transition_status']);
        $this->assertSame([], (array) $gate['self_programming_os_transition_blockers']);
        $this->assertTrue((bool) data_get($gate, 'self_programming_os_transition_readiness.contract_design_allowed'));
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.runtime_activation_allowed'));
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.self_programming_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $gate['self_programming_safety_contract_hash']);
    }

    public function test_gate_blocks_complete_claim_when_complete_audit_lacks_material_hashes(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true, includeMaterialEvidence: false);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('incomplete', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
        $this->assertFalse((bool) $gate['material_completion_evidence_green']);
        $this->assertContains('material_completion_evidence_hashes_missing_or_invalid', (array) $gate['next_stage_blocked_by']);
    }

    public function test_gate_blocks_complete_claim_when_terminal_loop_operational_proof_binding_is_missing(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true, includeTerminalLoopProof: false);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('incomplete', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
        $this->assertFalse((bool) $gate['terminal_loop_operational_proof_green']);
        $this->assertContains('terminal_loop_operational_proof_binding_missing_or_invalid', (array) $gate['next_stage_blocked_by']);
    }

    public function test_gate_blocks_next_stage_when_completion_allowed_false_in_audit(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true);
        $audit['completion_allowed'] = false;
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('incomplete', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
    }

    public function test_gate_never_persists_or_promotes_completion(): void
    {
        $gate = $this->gate()->evaluate();

        $this->assertFalse((bool) $gate['execution_allowed']);
        $this->assertFalse((bool) $gate['dispatch_allowed']);
        $this->assertFalse((bool) $gate['provider_call_allowed']);
        $this->assertFalse((bool) $gate['token_spend_allowed']);
        $this->assertFalse((bool) $gate['adapter_execution_allowed']);
        $this->assertFalse((bool) $gate['self_programming_allowed']);
        $this->assertContains('final_completion_readiness_gate_does_not_persist_evidence', (array) $gate['non_execution_guarantees']);
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.self_programming_allowed'));
        $this->assertContains('self_programming_transition_readiness_does_not_enable_self_programming', (array) data_get($gate, 'self_programming_os_transition_readiness.non_execution_guarantees'));
    }

    public function test_gate_hash_is_deterministic_with_fabricated_audit(): void
    {
        $audit = $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']);
        $first = $this->gate()->evaluate(['completion_audit' => $audit]);
        $second = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame($first['gate_hash'], $second['gate_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['gate_hash']);
    }

    public function test_gate_payload_is_json_serializable(): void
    {
        $gate = $this->gate()->evaluate();
        $encoded = json_encode($gate, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    public function test_gate_exposes_command_to_rerun_audit(): void
    {
        $gate = $this->gate()->evaluate();
        $this->assertStringContainsString('atlas:ai:self-construction', (string) $gate['command_to_rerun_audit']);
        $this->assertStringContainsString('completion-audit-status', (string) $gate['command_to_rerun_audit']);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) $gate['command_to_refresh_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) $gate['command_to_persist_terminal_loop_operational_proof_binding']);
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', (string) $gate['command_to_capture_snapshot_after_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $gate['command_to_rerun_audit_with_terminal_loop_operational_proof']);
        $this->assertSame([
            'refresh_terminal_loop_operational_proof_and_export_binding',
            'capture_replay_snapshot_after_terminal_loop_operational_proof',
            'rerun_completion_audit_with_terminal_loop_operational_proof_binding',
        ], array_column((array) $gate['final_verification_sequence'], 'id'));
        $this->assertTrue((bool) $gate['completion_audit_green_requires_current_snapshot_after_terminal_loop_proof']);
        $this->assertTrue((bool) $gate['terminal_loop_operational_proof_required_before_completion_claim']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            $gate['terminal_loop_operational_proof_expected_binding_schema'],
        );
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.completion_claim_requires_terminal_loop_operational_proof_binding'));
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.completion_claim_requires_current_snapshot_after_terminal_loop_operational_proof'));
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.self_programming_transition_requires_self_construction_complete'));
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.self_programming_transition_requires_safety_contract'));
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.self_programming_transition_does_not_enable_runtime'));
    }

    public function test_status_projection_exposes_terminal_loop_operational_proof_commands(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionFinalCompletionReadinessGateStatus();

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_completion_readiness_gate_status', []);

        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_required_before_completion_claim']);
        if ((bool) $summary['terminal_loop_operational_proof_green']) {
            $this->assertSame('passed', (string) $summary['terminal_loop_operational_proof_status']);
        } else {
            $this->assertFalse((bool) $summary['terminal_loop_operational_proof_green']);
        }
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            $summary['terminal_loop_operational_proof_expected_binding_schema'],
        );
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) $summary['command_to_refresh_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) $summary['command_to_persist_terminal_loop_operational_proof_binding']);
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', (string) $summary['command_to_capture_snapshot_after_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $summary['command_to_rerun_audit_with_terminal_loop_operational_proof']);
        $this->assertSame(3, (int) $summary['final_verification_sequence_step_count']);
        $this->assertSame('capture_replay_snapshot_after_terminal_loop_operational_proof', $summary['final_verification_sequence'][1]['id']);
        $this->assertTrue((bool) $summary['completion_audit_green_requires_current_snapshot_after_terminal_loop_proof']);
        $this->assertSame('blocked', (string) $summary['self_programming_os_transition_status']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['self_programming_os_transition_blockers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['self_programming_safety_contract_hash']);
        $this->assertFalse((bool) $summary['self_programming_runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
    }

    public function test_self_programming_os_transition_readiness_projection_is_blocked_until_self_construction_complete(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfProgrammingOsTransitionReadinessStatus();

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_programming_os_transition_readiness_status', []);

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_programming_os_transition_readiness_status.v1', $status['schema_version']);
        $this->assertSame('blocked', $status['status']);
        $this->assertSame('blocked', $summary['status']);
        $this->assertSame('Atlas Self-Programming OS', $summary['next_stage_name']);
        $this->assertFalse((bool) $summary['self_construction_complete']);
        $this->assertFalse((bool) $summary['contract_design_allowed']);
        $this->assertFalse((bool) $summary['runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
        $this->assertFalse((bool) $summary['provider_call_allowed']);
        $this->assertFalse((bool) $summary['token_spend_allowed']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['blockers']);
        $this->assertIsArray($summary['source_completion_audit_failed_criteria']);
        $this->assertGreaterThanOrEqual(3, (int) $summary['source_completion_audit_failed_criteria_count']);
        $this->assertGreaterThanOrEqual(3, (int) $summary['transition_blocker_count']);
        $this->assertSame(2, (int) $summary['operator_only_human_blocker_count']);
        $this->assertSame([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ], (array) $summary['operator_only_human_blockers']);
        $this->assertSame(1, (int) $summary['operator_only_real_provider_blocker_count']);
        $this->assertSame([
            'end_to_end_real_provider_smoke_green',
        ], (array) $summary['operator_only_real_provider_blockers']);
        $this->assertContains((string) $summary['current_required_operator_artifact'], [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ]);
        $this->assertStringContainsString(
            '--atlas-self-construction-operator-evidence-submission-readiness-status',
            (string) $summary['operator_evidence_readiness_command'],
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-final-operator-evidence-closure-corridor-status',
            (string) $summary['final_operator_evidence_closure_corridor_command'],
        );
        $this->assertFalse((bool) $summary['completion_claim_allowed']);
        $this->assertFalse((bool) $summary['next_stage_allowed']);
        $this->assertFalse((bool) $summary['next_stage_first_self_programming_task_allowed']);
        $this->assertFalse((bool) $summary['next_stage_runtime_activation_allowed']);
        $this->assertSame('docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md', $summary['safety_contract_path']);
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            (string) $summary['terminal_loop_operational_proof_canonical_binding_path'],
        );
        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_supplied_to_transition_readiness']);
        $this->assertSame('canonical_operator_submission', (string) $summary['terminal_loop_operational_proof_source']);
        $this->assertStringContainsString('--atlas-self-programming-os-transition-readiness-status', (string) $summary['transition_readiness_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString('--atlas-self-construction-os-completion-audit-status', (string) $summary['completion_audit_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            (string) $summary['transition_readiness_command_with_canonical_terminal_loop_binding'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['safety_contract_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['source_final_completion_readiness_gate_hash']);
    }

    public function test_cli_exposes_self_programming_os_transition_readiness_quartet(): void
    {
        foreach ([
            '--atlas-self-programming-os-transition-readiness-contract',
            '--atlas-self-programming-os-transition-readiness-preflight',
            '--atlas-self-programming-os-transition-readiness-implementation-packet',
        ] as $option) {
            Artisan::call('atlas:ai:self-construction', [
                $option => true,
                '--json' => true,
            ]);

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('atlas.self_programming.transition_readiness.v1', json_encode($payload, JSON_THROW_ON_ERROR));
            $this->assertFalse((bool) $payload['execution_allowed']);
            $this->assertFalse((bool) $payload['dispatch_allowed']);
        }

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-programming-os-transition-readiness-status' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $summary = (array) data_get($payload, 'agent_control_plane_atlas_self_programming_os_transition_readiness_status', []);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', $summary['status']);
        $this->assertFalse((bool) $summary['runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['blockers']);
        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_supplied_to_transition_readiness']);
        $this->assertSame('canonical_operator_submission', (string) $summary['terminal_loop_operational_proof_source']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', (string) $summary['completion_audit_command_with_canonical_terminal_loop_binding']);
    }

    public function test_agent_control_plane_lists_self_programming_transition_readiness_capabilities(): void
    {
        $payload = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('atlas_self_programming_os_transition_readiness_contract', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_preflight', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_implementation_packet', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_service', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_status_projection', $capabilities);
    }

    public function test_self_programming_safety_contract_certification_is_available_but_read_only(): void
    {
        $payload = (new AtlasSelfProgrammingSafetyContractCertificationService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        ))->certify();

        $this->assertSame('atlas.self_programming.safety_contract_certification.v1', $payload['schema_version']);
        $this->assertSame('read_only_self_programming_safety_contract_certification', $payload['mode']);
        $this->assertSame('available', $payload['status']);
        $this->assertTrue((bool) $payload['checks_all_true']);
        $this->assertSame([], (array) $payload['failed_check_ids']);
        $this->assertSame('blocked', (string) $payload['transition_status']);
        $this->assertContains('self_construction_os_not_complete', (array) $payload['transition_blockers']);
        $this->assertSame('blocked', (string) $payload['finalization_gate_status']);
        $this->assertSame('structural_probe_no_persistence_no_registry_side_effects', (string) $payload['finalization_gate_evaluation_mode']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['finalization_gate_hash']);
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            (string) $payload['terminal_loop_operational_proof_canonical_binding_path'],
        );
        $this->assertStringContainsString('--atlas-self-programming-os-transition-readiness-status', (string) $payload['transition_readiness_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString('--atlas-self-programming-safety-contract-certification-status', (string) $payload['safety_contract_certification_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString('--atlas-self-construction-os-completion-audit-status', (string) $payload['completion_audit_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            (string) $payload['safety_contract_certification_command_with_canonical_terminal_loop_binding'],
        );
        $this->assertFalse((bool) $payload['finalization_gate_terminal_loop_green']);
        $this->assertTrue((bool) $payload['finalization_gate_terminal_loop_required_before_completion_claim']);
        $this->assertFalse((bool) $payload['finalization_gate_completion_claim_allowed']);
        $this->assertFalse((bool) $payload['finalization_gate_next_stage_allowed']);
        $this->assertContains('finalization_gate_blocked_by_completion_audit_complete', (array) $payload['finalization_gate_next_stage_blockers']);
        $this->assertSame('blocked_operator_or_provider_evidence_required', (string) $payload['finalization_gate_operator_handoff_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['finalization_gate_operator_handoff_hash']);
        $this->assertContains((string) $payload['finalization_gate_current_required_operator_artifact'], [
            'runtime_promotion_receipt',
            'real_provider_smoke_certification',
            'human_signed_completion_receipt',
            'technical_completion_audit_repair',
            'completion_finalization_gate_repair',
        ]);
        $this->assertTrue((bool) data_get($payload, 'checks.finalization_gate_requires_terminal_loop_operational_proof'));
        $this->assertTrue((bool) data_get($payload, 'checks.finalization_gate_terminal_loop_check_uses_binding_evidence'));
        $this->assertTrue((bool) data_get($payload, 'checks.finalization_gate_blocks_completion_and_next_stage_until_all_checks_pass'));
        $this->assertTrue((bool) data_get($payload, 'checks.finalization_gate_operator_handoff_available'));
        $this->assertTrue((bool) data_get($payload, 'checks.finalization_gate_operator_handoff_is_read_only'));
        $this->assertTrue((bool) data_get($payload, 'checks.worker_task_eligibility_certification_available'));
        $this->assertTrue((bool) data_get($payload, 'checks.worker_task_eligibility_checks_all_true'));
        $this->assertTrue((bool) data_get($payload, 'checks.worker_task_eligibility_blocks_operator_only_completion_blockers'));
        $this->assertTrue((bool) data_get($payload, 'checks.self_programming_bootstrap_plan_available'));
        $this->assertTrue((bool) data_get($payload, 'checks.self_programming_bootstrap_plan_blocks_until_self_construction_complete'));
        $this->assertTrue((bool) data_get($payload, 'checks.self_programming_bootstrap_plan_is_read_only'));
        $this->assertSame('available', (string) $payload['worker_task_eligibility_status']);
        $this->assertSame(0, (int) $payload['worker_task_eligibility_violation_count']);
        $this->assertSame([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ], (array) $payload['worker_task_eligibility_operator_only_failed_criteria']);
        $this->assertSame([], (array) $payload['worker_task_eligibility_missing_operator_handoff_criteria']);
        $this->assertGreaterThanOrEqual(3, (int) $payload['worker_task_eligibility_operator_handoff_seed_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['worker_task_eligibility_certification_hash']);
        $this->assertSame('blocked_until_self_construction_complete', (string) $payload['self_programming_bootstrap_plan_status']);
        $this->assertSame(4, (int) $payload['self_programming_bootstrap_plan_phase_count']);
        $this->assertContains('self_construction_os_not_complete', (array) data_get($payload, 'self_programming_bootstrap_plan.blockers'));
        $this->assertContains('terminal_loop_operational_proof_binding_green', (array) data_get($payload, 'self_programming_bootstrap_plan.required_before_first_self_programming_task'));
        $checklist = collect((array) $payload['next_stage_prompt_to_artifact_checklist'])->keyBy('requirement');
        $this->assertSame(5, (int) $payload['next_stage_prompt_to_artifact_checklist_count']);
        $this->assertGreaterThanOrEqual(3, (int) $payload['next_stage_prompt_to_artifact_checklist_passed_count']);
        $this->assertSame('blocked_until_completion_audit_complete', (string) data_get($checklist->get('Self-Construction OS complete before Self-Programming'), 'evidence_status'));
        $this->assertSame('passed', (string) data_get($checklist->get('worker task eligibility blocks final human/provider artifacts from worker claim'), 'evidence_status'));
        $this->assertSame('passed', (string) data_get($checklist->get('Self-Programming bootstrap plan exists but remains read-only'), 'evidence_status'));
        $this->assertSame('passed', (string) data_get($checklist->get('provider calls, token spend, dispatch and self-programming runtime stay disabled'), 'evidence_status'));
        $this->assertFalse((bool) data_get($payload, 'self_programming_bootstrap_plan.can_create_mutation_tasks'));
        $this->assertFalse((bool) data_get($payload, 'self_programming_bootstrap_plan.can_apply_patches'));
        $this->assertFalse((bool) data_get($payload, 'self_programming_bootstrap_plan.can_call_provider'));
        $this->assertFalse((bool) data_get($payload, 'self_programming_bootstrap_plan.can_spend_tokens'));
        $this->assertFalse((bool) data_get($payload, 'self_programming_bootstrap_plan.can_dispatch_work'));
        $this->assertFalse((bool) data_get($payload, 'self_programming_bootstrap_plan.can_enable_self_programming_runtime'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['self_programming_bootstrap_plan_hash']);
        $contractDoc = (string) file_get_contents(base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md'));
        $this->assertStringContainsString('atlas.self_programming.bootstrap_plan.v1', $contractDoc);
        $this->assertStringContainsString('read_only_self_programming_bootstrap_plan', $contractDoc);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['safety_contract_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['certification_hash']);
        $this->assertFalse((bool) $payload['runtime_activation_allowed']);
        $this->assertFalse((bool) $payload['self_programming_allowed']);
        $this->assertFalse((bool) $payload['provider_call_allowed']);
        $this->assertFalse((bool) $payload['token_spend_allowed']);
        $this->assertContains('self_programming_safety_contract_certification_does_not_enable_self_programming', (array) $payload['non_execution_guarantees']);
    }

    public function test_self_programming_safety_contract_certification_status_projection(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfProgrammingSafetyContractCertificationStatus();

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_programming_safety_contract_certification_status', []);

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_programming_safety_contract_certification_status.v1', $status['schema_version']);
        $this->assertSame('available', $status['status']);
        $this->assertSame('available', $summary['status']);
        $this->assertSame('blocked', $summary['transition_status']);
        $this->assertSame('blocked', (string) $summary['finalization_gate_status']);
        $this->assertSame('structural_probe_no_persistence_no_registry_side_effects', (string) $summary['finalization_gate_evaluation_mode']);
        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_supplied_to_safety_certification']);
        $this->assertSame('canonical_operator_submission', (string) $summary['terminal_loop_operational_proof_source']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['finalization_gate_hash']);
        $this->assertFalse((bool) $summary['finalization_gate_terminal_loop_green']);
        $this->assertTrue((bool) $summary['finalization_gate_terminal_loop_required_before_completion_claim']);
        $this->assertFalse((bool) $summary['finalization_gate_completion_claim_allowed']);
        $this->assertFalse((bool) $summary['finalization_gate_next_stage_allowed']);
        $this->assertContains('finalization_gate_blocked_by_completion_audit_complete', (array) $summary['finalization_gate_next_stage_blockers']);
        $this->assertSame('blocked_operator_or_provider_evidence_required', (string) $summary['finalization_gate_operator_handoff_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['finalization_gate_operator_handoff_hash']);
        $this->assertContains((string) $summary['finalization_gate_current_required_operator_artifact'], [
            'runtime_promotion_receipt',
            'real_provider_smoke_certification',
            'human_signed_completion_receipt',
            'technical_completion_audit_repair',
            'completion_finalization_gate_repair',
        ]);
        $this->assertStringContainsString(
            '--atlas-self-construction-operator-evidence-submission-readiness-status',
            (string) $summary['finalization_gate_operator_evidence_readiness_command'],
        );
        $this->assertGreaterThanOrEqual(3, (int) $summary['finalization_gate_final_verification_sequence_count']);
        $this->assertFalse((bool) $summary['runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
        $this->assertSame('available', (string) $summary['worker_task_eligibility_status']);
        $this->assertSame(0, (int) $summary['worker_task_eligibility_violation_count']);
        $this->assertSame([], (array) $summary['worker_task_eligibility_missing_operator_handoff_criteria']);
        $this->assertGreaterThanOrEqual(3, (int) $summary['worker_task_eligibility_operator_handoff_seed_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['worker_task_eligibility_certification_hash']);
        $this->assertSame(3, (int) $summary['next_stage_operator_only_blocker_count']);
        $this->assertSame(2, (int) $summary['next_stage_operator_only_human_blocker_count']);
        $this->assertSame([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ], (array) $summary['next_stage_operator_only_human_blockers']);
        $this->assertSame(1, (int) $summary['next_stage_operator_only_real_provider_blocker_count']);
        $this->assertSame([
            'end_to_end_real_provider_smoke_green',
        ], (array) $summary['next_stage_operator_only_real_provider_blockers']);
        $this->assertContains((string) $summary['next_stage_current_required_closure_artifact'], [
            'runtime_promotion_receipt',
            'real_provider_smoke_certification',
            'human_signed_completion_receipt',
            'technical_completion_audit_repair',
            'completion_finalization_gate_repair',
        ]);
        $this->assertIsBool($summary['next_stage_terminal_loop_binding_green']);
        $this->assertFalse((bool) $summary['next_stage_first_self_programming_task_allowed']);
        $this->assertSame('blocked_until_self_construction_complete', (string) $summary['self_programming_bootstrap_plan_status']);
        $this->assertSame(4, (int) $summary['self_programming_bootstrap_plan_phase_count']);
        $this->assertSame(5, (int) $summary['next_stage_prompt_to_artifact_checklist_count']);
        $this->assertGreaterThanOrEqual(3, (int) $summary['next_stage_prompt_to_artifact_checklist_passed_count']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['self_programming_bootstrap_plan_blockers']);
        $bootstrapFinalizationGateSource = (string) $summary['self_programming_bootstrap_plan_finalization_gate_source'];
        $this->assertContains($bootstrapFinalizationGateSource, [
            'structural_finalization_gate_probe',
            'live_finalization_gate_with_operator_terminal_loop_binding',
        ]);
        if ($bootstrapFinalizationGateSource === 'live_finalization_gate_with_operator_terminal_loop_binding') {
            $this->assertTrue((bool) $summary['self_programming_bootstrap_plan_finalization_gate_terminal_loop_green']);
            $this->assertNotContains('terminal_loop_green', (array) $summary['self_programming_bootstrap_plan_finalization_gate_failed_check_ids']);
            $this->assertNotContains('finalization_gate_blocked_by_terminal_loop_green', (array) $summary['self_programming_bootstrap_plan_finalization_gate_next_stage_blockers']);
            $this->assertStringContainsString(
                '--atlas-self-construction-operator-evidence-submission-readiness-status',
                (string) $summary['live_finalization_gate_operator_evidence_readiness_command'],
            );
            $this->assertGreaterThanOrEqual(3, (int) $summary['live_finalization_gate_final_verification_sequence_count']);
        } else {
            $this->assertFalse((bool) $summary['self_programming_bootstrap_plan_finalization_gate_terminal_loop_green']);
            $this->assertContains('terminal_loop_green', (array) $summary['self_programming_bootstrap_plan_finalization_gate_failed_check_ids']);
            $this->assertContains('finalization_gate_blocked_by_terminal_loop_green', (array) $summary['self_programming_bootstrap_plan_finalization_gate_next_stage_blockers']);
        }
        $this->assertContains('terminal_loop_operational_proof_binding_green', (array) $summary['self_programming_bootstrap_plan_required_before_first_task']);
        $this->assertFalse((bool) $summary['self_programming_bootstrap_plan_can_create_mutation_tasks']);
        $this->assertFalse((bool) $summary['self_programming_bootstrap_plan_can_apply_patches']);
        $this->assertFalse((bool) $summary['self_programming_bootstrap_plan_can_call_provider']);
        $this->assertFalse((bool) $summary['self_programming_bootstrap_plan_can_spend_tokens']);
        $this->assertFalse((bool) $summary['self_programming_bootstrap_plan_can_dispatch_work']);
        $this->assertFalse((bool) $summary['self_programming_bootstrap_plan_can_enable_self_programming_runtime']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['self_programming_bootstrap_plan_hash']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['transition_blockers']);
        $this->assertSame('docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md', $summary['safety_contract_path']);
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            (string) $summary['terminal_loop_operational_proof_canonical_binding_path'],
        );
        $this->assertStringContainsString('--atlas-self-programming-os-transition-readiness-status', (string) $summary['transition_readiness_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString('--atlas-self-programming-safety-contract-certification-status', (string) $summary['safety_contract_certification_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString('--atlas-self-construction-os-completion-audit-status', (string) $summary['completion_audit_command_with_canonical_terminal_loop_binding']);
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            (string) $summary['completion_audit_command_with_canonical_terminal_loop_binding'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['safety_contract_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['certification_hash']);
        $this->assertSame([], (array) $summary['failed_check_ids']);
    }

    public function test_self_programming_safety_contract_certification_exposes_live_finalization_gate_when_proof_path_is_supplied(): void
    {
        $reference = '@/tmp/terminal-loop-operational-proof-binding.json';
        $audit = $this->auditAllPassedExcept(['runtime_gap_matrix_all_runtime_y'], includeTerminalLoopProof: true);
        $audit['criteria'][] = [
            'id' => 'agent_control_plane_terminal_loop_certification_green',
            'passed' => true,
            'evidence' => ['status' => 'available'],
        ];
        $payload = (new AtlasSelfProgrammingSafetyContractCertificationService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        ))->certify([
            'completion_audit' => $audit,
            'agent_control_plane_terminal_loop_operational_proof_json' => $reference,
        ]);

        $this->assertSame('available', $payload['status']);
        $this->assertSame($reference, $payload['terminal_loop_operational_proof_json_reference']);
        $this->assertTrue((bool) $payload['live_finalization_gate_available']);
        $this->assertSame('blocked', (string) $payload['live_finalization_gate_status']);
        $this->assertTrue((bool) $payload['live_finalization_gate_terminal_loop_green']);
        $this->assertSame('passed', (string) $payload['live_finalization_gate_terminal_loop_proof_status']);
        $this->assertSame('live_finalization_gate_with_operator_terminal_loop_binding', (string) data_get($payload, 'self_programming_bootstrap_plan.finalization_gate_source'));
        $this->assertTrue((bool) data_get($payload, 'self_programming_bootstrap_plan.finalization_gate_terminal_loop_green'));
        $this->assertNotContains('finalization_gate_blocked_by_terminal_loop_green', (array) data_get($payload, 'self_programming_bootstrap_plan.blockers'));
        $this->assertNotContains('terminal_loop_operational_proof_binding_missing_or_invalid', (array) data_get($payload, 'self_programming_bootstrap_plan.blockers'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) $payload['live_finalization_gate_command'],
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) $payload['live_finalization_gate_audit_with_binding_command'],
        );
    }

    public function test_cli_exposes_self_programming_safety_contract_certification_quartet(): void
    {
        foreach ([
            '--atlas-self-programming-safety-contract-certification-contract',
            '--atlas-self-programming-safety-contract-certification-preflight',
            '--atlas-self-programming-safety-contract-certification-implementation-packet',
        ] as $option) {
            Artisan::call('atlas:ai:self-construction', [
                $option => true,
                '--json' => true,
            ]);

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('atlas.self_programming.safety_contract_certification.v1', json_encode($payload, JSON_THROW_ON_ERROR));
            $this->assertFalse((bool) $payload['execution_allowed']);
            $this->assertFalse((bool) $payload['dispatch_allowed']);
        }

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-programming-safety-contract-certification-status' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $summary = (array) data_get($payload, 'agent_control_plane_atlas_self_programming_safety_contract_certification_status', []);

        $this->assertSame('available', $payload['status']);
        $this->assertSame('available', $summary['status']);
        $this->assertFalse((bool) $summary['runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
        $this->assertSame([], (array) $summary['failed_check_ids']);
    }

    public function test_agent_control_plane_lists_self_programming_safety_contract_certification_capabilities(): void
    {
        $payload = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('atlas_self_programming_safety_contract_certification_contract', $capabilities);
        $this->assertContains('atlas_self_programming_safety_contract_certification_preflight', $capabilities);
        $this->assertContains('atlas_self_programming_safety_contract_certification_implementation_packet', $capabilities);
        $this->assertContains('atlas_self_programming_safety_contract_certification_service', $capabilities);
        $this->assertContains('atlas_self_programming_safety_contract_certification_status_projection', $capabilities);
    }

    public function test_gate_preserves_terminal_loop_proof_path_in_final_verification_commands(): void
    {
        $reference = '@/tmp/terminal-loop-operational-proof-binding.json';
        $gate = $this->gate()->evaluate([
            'completion_audit' => $this->auditAllPassedExcept(['runtime_gap_matrix_all_runtime_y']),
            'agent_control_plane_terminal_loop_operational_proof_json' => $reference,
        ]);

        $this->assertSame($reference, $gate['terminal_loop_operational_proof_json_reference']);
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) $gate['command_to_rerun_audit_with_terminal_loop_operational_proof'],
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) data_get($gate, 'final_verification_sequence.2.command'),
        );
    }

    private function gate(): AtlasSelfConstructionFinalCompletionReadinessGateService
    {
        return new AtlasSelfConstructionFinalCompletionReadinessGateService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    /**
     * @param  list<string>  $failed
     * @return array<string, mixed>
     */
    private function auditAllPassedExcept(array $failed, bool $complete = false, bool $includeMaterialEvidence = true, bool $includeTerminalLoopProof = true): array
    {
        $hash = str_repeat('a', 64);
        $ids = [
            'runtime_gap_matrix_all_runtime_y',
            'release_dossier_green',
            'replay_diff_against_completion_snapshot_green',
            'promotion_gate_green',
            'mutation_guard_green',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
            'forge_self_improvement_integration_smoke_green',
            'certification_status_batch_green',
        ];
        $criteria = [];
        foreach ($ids as $id) {
            $evidence = [];
            if ($includeMaterialEvidence) {
                $evidence = match ($id) {
                    'runtime_gap_matrix_all_runtime_y' => [
                        'runtime_gap_matrix_hash' => $hash,
                        'runtime_promotion_receipt_hash' => $hash,
                    ],
                    'human_signed_os_complete_receipt_present' => [
                        'receipt_hash' => $hash,
                    ],
                    'end_to_end_real_provider_smoke_green' => [
                        'smoke_hash' => $hash,
                    ],
                    'release_dossier_green' => [
                        'hash' => $hash,
                    ],
                    'replay_diff_against_completion_snapshot_green' => [
                        'diff_hash' => $hash,
                    ],
                    'certification_status_batch_green' => [
                        'hash' => $hash,
                    ],
                    default => [],
                };
            }
            $criteria[] = ['id' => $id, 'passed' => ! in_array($id, $failed, true), 'evidence' => $evidence];
        }

        $audit = [
            'status' => $failed === [] && $complete ? 'complete' : 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => count($ids) - count($failed),
            'completion_allowed' => $failed === [] && $complete,
            'completion_claim_allowed' => $failed === [] && $complete,
            'criteria' => $criteria,
        ];

        if ($includeTerminalLoopProof) {
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence'] = [
                'status' => 'passed',
                'supplied' => true,
                'passed' => true,
                'proof_hash' => $hash,
                'validation_violation_count' => 0,
                'post_cycle_end_to_end_contract_status' => 'terminal_loop_end_to_end_contract_available',
                'post_cycle_end_to_end_contract_all_required_surfaces_present' => true,
                'post_cycle_end_to_end_contract_failed_check_ids' => [],
                'post_cycle_end_to_end_contract_missing_required_capabilities' => [],
                'post_cycle_end_to_end_contract_hash' => $hash,
                'post_cycle_cleanup_state' => [
                    'claimed_task_count' => 0,
                    'active_lease_count' => 0,
                    'recoverable_lease_count' => 0,
                ],
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ];
        }

        return $audit;
    }
}
