<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionEvidenceSubmissionPreflightTest extends TestCase
{
    public function test_submission_preflight_reports_first_missing_runtime_receipt_without_persisting(): void
    {
        $payload = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
            completionAudit: $this->completionAudit(['runtime_gap_matrix_all_runtime_y', 'end_to_end_real_provider_smoke_green']),
            completionEvidence: $this->completionEvidence(),
            blockerExplainer: $this->blockerExplainer(),
        );

        $this->assertSame('atlas.self_construction.completion_evidence_submission_preflight.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('runtime_promotion_receipt', $payload['next_required_submission']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $payload['next_required_command']);
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_execution_plan.current_step'));
        $this->assertFalse((bool) data_get($payload, 'operator_execution_plan.parallel_submission_allowed'));
        $this->assertContains('stop_if_receipt_hash_does_not_match_payload', data_get($payload, 'operator_execution_plan.stop_conditions'));
        $this->assertCount(5, data_get($payload, 'operator_execution_plan.ordered_command_queue'));
        $this->assertSame('atlas.self_construction.operator_final_evidence_handoff_packet.v1', data_get($payload, 'operator_handoff_packet.schema_version'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_handoff_packet.current_step'));
        $this->assertSame(['adapter_execution_runtime'], data_get($payload, 'operator_handoff_packet.current_blockers'));
        $this->assertContains('operator_signed_runtime_promotion_receipt_json', data_get($payload, 'operator_handoff_packet.required_operator_inputs'));
        $this->assertContains('stop_if_any_required_input_is_placeholder', data_get($payload, 'operator_handoff_packet.handoff_stop_conditions'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_handoff_packet.handoff_packet_hash'));
        $this->assertSame('atlas.self_construction.operator_final_evidence_resumption_checkpoint.v1', data_get($payload, 'operator_resumption_checkpoint.schema_version'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_resumption_checkpoint.current_step'));
        $this->assertTrue(data_get($payload, 'operator_resumption_checkpoint.can_resume_without_chat_history'));
        $this->assertTrue(data_get($payload, 'operator_resumption_checkpoint.requires_fresh_preflight_before_persist'));
        $this->assertFalse(data_get($payload, 'operator_resumption_checkpoint.parallel_submission_allowed'));
        $this->assertSame('completion_audit.status=complete AND completion_allowed=true AND failed_count=0', data_get($payload, 'operator_resumption_checkpoint.final_success_predicate'));
        $this->assertContains('refresh_completion_audit', array_keys(data_get($payload, 'operator_resumption_checkpoint.resume_commands')));
        $this->assertContains('stop_if_current_step_changed_after_resume', data_get($payload, 'operator_resumption_checkpoint.stop_conditions'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_resumption_checkpoint.resumption_checkpoint_hash'));
        $this->assertSame(
            data_get($payload, 'operator_resumption_checkpoint.resumption_checkpoint_hash'),
            data_get($payload, 'operator_handoff_packet.resumption_checkpoint_hash'),
        );
        $this->assertSame('atlas.self_construction.operator_closure_command_replay.v1', data_get($payload, 'operator_closure_command_replay.schema_version'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_closure_command_replay.current_step'));
        $this->assertSame(5, data_get($payload, 'operator_closure_command_replay.replay_step_count'));
        $this->assertFalse(data_get($payload, 'operator_closure_command_replay.parallel_submission_allowed'));
        $this->assertTrue(data_get($payload, 'operator_closure_command_replay.requires_fresh_preflight_before_every_persist'));
        $this->assertTrue(data_get($payload, 'operator_closure_command_replay.requires_fresh_completion_audit_after_every_persist'));
        $this->assertContains('stop_if_any_guard_hash_changes_before_persist', data_get($payload, 'operator_closure_command_replay.stop_conditions'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_closure_command_replay.command_replay_hash'));
        $this->assertSame(
            data_get($payload, 'operator_closure_command_replay.command_replay_hash'),
            data_get($payload, 'operator_handoff_packet.operator_closure_command_replay_hash'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-completion-evidence-submission-preflight-status',
            data_get($payload, 'operator_closure_command_replay.proof_commands_after_each_persist.submission_preflight'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-status',
            data_get($payload, 'operator_closure_command_replay.proof_commands_after_each_persist.terminal_loop_operational_proof'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_closure_command_replay.proof_commands_after_each_persist.completion_audit'),
        );
        $this->assertGreaterThanOrEqual(7, $payload['pre_persist_guardrail_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['pre_persist_guardrail_hash']);
        $prePersistGuardrails = collect($payload['pre_persist_guardrail_sequence'])->keyBy('step');
        $this->assertStringContainsString('--atlas-self-construction-os-handoff-status', $prePersistGuardrails['inspect_self_construction_handoff']['command']);
        $this->assertStringContainsString('--agent-control-plane-certification-status-batch-status', $prePersistGuardrails['verify_certification_status_batch_green']['command']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-endgame-verifier-status', $prePersistGuardrails['verify_current_artifact_endgame']['command']);
        $this->assertTrue($prePersistGuardrails['verify_current_artifact_endgame']['requires_operator_payload']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $prePersistGuardrails['persist_current_operator_artifact']['command']);
        $this->assertSame('atlas.self_construction.terminal_loop_closure_proof_packet.v1', data_get($payload, 'terminal_loop_closure_proof.schema_version'));
        $this->assertSame('operator_or_ci_should_refresh_before_final_persist', data_get($payload, 'terminal_loop_closure_proof.status'));
        $this->assertTrue(data_get($payload, 'terminal_loop_closure_proof.required_before_final_completion_receipt'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', data_get($payload, 'terminal_loop_closure_proof.proof_command'));
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', data_get($payload, 'terminal_loop_closure_proof.proof_binding_persist_command'));
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'terminal_loop_closure_proof.expected_binding_artifact_path'),
        );
        $this->assertContains('persist_completion_audit_binding_packet_to_canonical_operator_submission_path', data_get($payload, 'terminal_loop_closure_proof.operator_steps'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json', data_get($payload, 'terminal_loop_closure_proof.audit_command_with_binding'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'terminal_loop_closure_proof.audit_command_with_canonical_binding'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'terminal_loop_closure_proof.effective_audit_command_with_binding'),
        );
        $this->assertContains('post_cycle_cycle_supervisor_review_evidence', data_get($payload, 'terminal_loop_closure_proof.acceptance_criteria'));
        $this->assertContains('post_cycle_end_to_end_contract_available', data_get($payload, 'terminal_loop_closure_proof.acceptance_criteria'));
        $this->assertContains('post_cycle_end_to_end_contract_covers_required_loop_surfaces', data_get($payload, 'terminal_loop_closure_proof.acceptance_criteria'));
        $this->assertEqualsCanonicalizing(
            [
                'auto_replenishment',
                'validation',
                'leases',
                'evidence',
                'retomada',
                'lane_isolation',
                'cycle_supervision',
                'operator_handoff',
            ],
            data_get($payload, 'terminal_loop_closure_proof.required_end_to_end_contract_capabilities'),
        );
        $this->assertContains('stop_if_post_cycle_cycle_supervisor_is_not_review_evidence', data_get($payload, 'terminal_loop_closure_proof.stop_conditions'));
        $this->assertContains('stop_if_post_cycle_end_to_end_contract_is_not_available', data_get($payload, 'terminal_loop_closure_proof.stop_conditions'));
        $this->assertContains('stop_if_post_cycle_end_to_end_contract_has_missing_loop_surfaces', data_get($payload, 'terminal_loop_closure_proof.stop_conditions'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'terminal_loop_closure_proof.terminal_loop_closure_proof_packet_hash'));
        $this->assertSame('atlas.self_construction.completion_evidence_submission_preflight.command_surface.v1', data_get($payload, 'operator_command_surface.schema_version'));
        $this->assertSame('available', data_get($payload, 'operator_command_surface.status'));
        $this->assertTrue(data_get($payload, 'operator_command_surface.all_commands_available'));
        $this->assertTrue(data_get($payload, 'operator_command_surface.legacy_alias_free'));
        $this->assertGreaterThanOrEqual(9, (int) data_get($payload, 'operator_command_surface.command_count'));
        $this->assertSame(0, data_get($payload, 'operator_command_surface.missing_option_count'));
        $this->assertSame(0, data_get($payload, 'operator_command_surface.legacy_alias_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_command_surface.command_surface_hash'));
        $this->assertTrue(data_get($payload, 'operator_handoff_packet.can_resume_without_chat_history'));
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertContains('completion_evidence_submission_preflight_does_not_persist_receipts', $payload['non_execution_guarantees']);
    }

    public function test_submission_preflight_advances_to_real_provider_smoke_after_runtime_receipt(): void
    {
        $evidence = $this->completionEvidence();
        data_set($evidence, 'runtime_gap_matrix.all_runtime_y', true);
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'passed');
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', str_repeat('a', 64));

        $payload = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
            completionAudit: $this->completionAudit(['end_to_end_real_provider_smoke_green']),
            completionEvidence: $evidence,
            blockerExplainer: $this->blockerExplainer(),
        );

        $this->assertSame('real_provider_smoke', $payload['next_required_submission']);
        $this->assertSame('real_provider_smoke', data_get($payload, 'operator_execution_plan.current_step'));
        $this->assertSame('real_provider_smoke', data_get($payload, 'operator_handoff_packet.current_step'));
        $this->assertContains('provider_run_id', data_get($payload, 'operator_handoff_packet.required_operator_inputs'));
        $this->assertContains('runtime_promotion_receipt', data_get($payload, 'operator_handoff_packet.required_before_current_step'));
        $this->assertStringContainsString('--atlas-self-construction-real-provider-smoke-draft-status', $payload['next_required_command']);
        $this->assertStringContainsString('--persist-completion-evidence', $payload['next_required_persist_command']);
    }

    public function test_submission_preflight_marks_hash_composition_ready_after_runtime_and_smoke(): void
    {
        $evidence = $this->completionEvidence();
        data_set($evidence, 'runtime_gap_matrix.all_runtime_y', true);
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'passed');
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', str_repeat('a', 64));
        data_set($evidence, 'real_provider_smoke.status', 'passed');
        data_set($evidence, 'real_provider_smoke.smoke_hash', str_repeat('b', 64));
        data_set($evidence, 'real_provider_smoke.violations', []);

        $payload = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
            completionAudit: $this->completionAudit(['human_signed_os_complete_receipt_present']),
            completionEvidence: $evidence,
            blockerExplainer: $this->blockerExplainer(),
        );

        $steps = collect($payload['ordered_steps'])->keyBy('id');

        $this->assertTrue(data_get($steps, 'completion_evidence_hash_composition.ready'));
        $this->assertSame('ready_for_operator_hash_composition', data_get($steps, 'completion_evidence_hash_composition.status'));
        $this->assertSame('human_completion_receipt', $payload['next_required_submission']);
        $this->assertSame('human_completion_receipt', data_get($payload, 'operator_execution_plan.current_step'));
        $this->assertSame('human_completion_receipt', data_get($payload, 'operator_handoff_packet.current_step'));
        $this->assertContains('operator_signed_human_completion_receipt_json', data_get($payload, 'operator_handoff_packet.required_operator_inputs'));
        $this->assertFalse((bool) data_get($payload, 'operator_handoff_packet.parallel_submission_allowed'));
        $this->assertSame('completion_audit.status=complete AND completion_allowed=true AND failed_count=0', data_get($payload, 'operator_execution_plan.final_success_predicate'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_execution_plan.final_success_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_execution_plan.effective_final_success_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_handoff_packet.effective_final_success_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_resumption_checkpoint.resume_commands.effective_refresh_completion_audit'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_closure_command_replay.proof_commands_after_each_persist.effective_completion_audit'),
        );
    }

    public function test_submission_preflight_exposes_readiness_and_cli_surface(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionCompletionEvidenceSubmissionPreflightStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.v1', $status['schema_version']);
        $this->assertSame('blocked', $status['status']);
        $this->assertSame('runtime_promotion_receipt', data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.next_required_submission'));
        $this->assertSame('runtime_promotion_receipt', data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.current_required_operator_artifact'));
        $this->assertGreaterThanOrEqual(5, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.current_required_operator_input_count'));
        $this->assertContains(
            'operator_signed_runtime_promotion_receipt_json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.current_required_operator_inputs'),
        );
        $this->assertGreaterThanOrEqual(7, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.current_step_stop_condition_count'));
        $this->assertContains(
            'stop_if_any_required_input_is_placeholder',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.current_step_stop_conditions'),
        );
        $this->assertGreaterThanOrEqual(1, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.current_completion_blocker_classification_count'));
        $this->assertSame(
            'runtime_gap_matrix_all_runtime_y',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.current_completion_blockers_classified.0.id'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.next_required_command'),
        );
        $this->assertStringContainsString(
            '--persist-runtime-promotion-receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.next_required_persist_command'),
        );
        $this->assertIsInt(data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.completion_audit_failed_count'));
        $this->assertIsInt(data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.completion_audit_technical_blocker_count'));
        $this->assertIsArray(data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.completion_audit_failed_criteria'));
        $this->assertSame('runtime_promotion_receipt', data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.resumption_checkpoint_current_step'));
        $this->assertTrue(data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.resumption_checkpoint_can_resume_without_chat_history'));
        $this->assertTrue(data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.resumption_checkpoint_requires_fresh_preflight_before_persist'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.resumption_checkpoint_hash'));
        $this->assertSame('runtime_promotion_receipt', data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_closure_command_replay_current_step'));
        $this->assertSame(5, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_closure_command_replay_step_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_closure_command_replay_hash'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_closure_command_replay_effective_completion_audit_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_execution_plan_effective_final_success_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_handoff_packet_effective_final_success_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_resumption_checkpoint_effective_refresh_completion_audit_command'),
        );
        $this->assertSame('operator_or_ci_should_refresh_before_final_persist', data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_status'));
        $this->assertTrue(data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_required_before_final_receipt'));
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_canonical_binding_path'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_audit_command_with_canonical_binding'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_effective_audit_command_with_binding'),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_packet_hash'));
        $this->assertSame(8, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_required_end_to_end_contract_capability_count'));
        $this->assertContains(
            'retomada',
            data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.terminal_loop_closure_proof_required_end_to_end_contract_capabilities'),
        );
        $this->assertSame('available', data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_command_surface_status'));
        $this->assertGreaterThanOrEqual(9, (int) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_command_count'));
        $this->assertSame(0, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_command_missing_option_count'));
        $this->assertSame(0, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_command_legacy_alias_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.operator_command_surface_hash'));
        $this->assertSame(4, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.closure_artifact_sequence_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.closure_artifact_sequence_hash'));
        $this->assertSame(4, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.prompt_to_artifact_checklist_count'));
        $this->assertSame(0, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.prompt_to_artifact_checklist_passed_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.prompt_to_artifact_checklist_hash'));
        $this->assertGreaterThanOrEqual(7, data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.pre_persist_guardrail_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.pre_persist_guardrail_hash'));
        $prePersistGuardrailSteps = array_column((array) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.pre_persist_guardrail_sequence'), 'step');
        $this->assertContains('verify_certification_status_batch_green', $prePersistGuardrailSteps);
        $this->assertContains('verify_current_artifact_endgame', $prePersistGuardrailSteps);
        $sequence = collect((array) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.closure_artifact_sequence'))->keyBy('requirement');
        $checklist = collect((array) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.prompt_to_artifact_checklist'))->keyBy('requirement');
        $this->assertSame('runtime_promotion_receipt', data_get($sequence, 'runtime_gap_matrix_all_runtime_y.artifact'));
        $this->assertSame('real_provider_smoke', data_get($checklist, 'end_to_end_real_provider_smoke_green.artifact'));
        $this->assertFalse($status['execution_allowed']);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-completion-evidence-submission-preflight-contract' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_contract.v1', $payload['schema_version']);
        $this->assertFalse($payload['dispatch_allowed']);
    }

    public function test_submission_preflight_status_loads_canonical_terminal_loop_binding_without_explicit_option(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            json_encode(['proof_payload' => $this->terminalLoopProofPayload()], JSON_THROW_ON_ERROR),
        );

        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $preflight = $readiness->atlasSelfConstructionCompletionEvidenceSubmissionPreflightStatus();
        $status = (array) data_get($preflight, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status');

        $this->assertTrue((bool) $status['terminal_loop_operational_proof_supplied_to_completion_audit']);
        $this->assertSame('canonical_operator_submission', $status['terminal_loop_operational_proof_source']);
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            $status['terminal_loop_operational_proof_canonical_path'],
        );

        $audit = $readiness->atlasSelfConstructionOsCompletionAuditStatus();
        $auditStatus = (array) data_get($audit, 'agent_control_plane_atlas_self_construction_os_completion_audit_status');

        $this->assertTrue((bool) $auditStatus['terminal_loop_operational_proof_supplied']);
        $this->assertTrue((bool) $auditStatus['terminal_loop_operational_proof_passed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $auditStatus['terminal_loop_operational_proof_hash']);
    }

    public function test_submission_preflight_human_output_exposes_operator_handoff_fields(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-completion-evidence-submission-preflight-status' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Next required submission', $output);
        $this->assertStringContainsString('runtime_promotion_receipt', $output);
        $this->assertStringContainsString('Current required artifact', $output);
        $this->assertStringContainsString('Completion audit failed', $output);
        $this->assertStringContainsString('Technical blockers', $output);
        $this->assertStringContainsString('Human blockers', $output);
        $this->assertStringContainsString('Real provider blockers', $output);
        $this->assertStringContainsString('Terminal proof supplied', $output);
        $this->assertStringContainsString('Terminal proof source', $output);
        $this->assertStringContainsString('Can resume without chat', $output);
        $this->assertStringContainsString('Fresh preflight before persist', $output);
        $this->assertStringContainsString('Required operator inputs', $output);
        $this->assertStringContainsString('Pre-persist guardrails', $output);
        $this->assertStringContainsString('operator_signed_runtime_promotion_receipt_json', $output);
        $this->assertStringContainsString('Stop conditions', $output);
        $this->assertStringContainsString('Current step stop conditions:', $output);
        $this->assertStringContainsString('stop_if_any_required_input_is_placeholder', $output);
        $this->assertStringContainsString('Pre-persist guardrail sequence:', $output);
        $this->assertStringContainsString('verify_certification_status_batch_green', $output);
        $this->assertStringContainsString('verify_current_artifact_endgame', $output);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-endgame-verifier-status', $output);
        $this->assertStringContainsString('Command surface', $output);
        $this->assertStringContainsString('Missing CLI options', $output);
        $this->assertStringContainsString('Legacy aliases', $output);
        $this->assertStringContainsString('Next command', $output);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $output);
        $this->assertStringContainsString('Persist command', $output);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $output);
        $this->assertStringContainsString('Final audit command', $output);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $output);
        $this->assertStringContainsString('Submission preflight steps:', $output);
        $this->assertStringContainsString('[blocked]', $output);
    }

    public function test_agent_control_plane_lists_submission_preflight_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_contract', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_preflight', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_implementation_packet', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_service', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_status_projection', $capabilities);
    }

    /** @param list<string> $failedCriteria */
    private function completionAudit(array $failedCriteria): array
    {
        return [
            'status' => $failedCriteria === [] ? 'complete' : 'incomplete',
            'completion_allowed' => $failedCriteria === [],
            'completion_audit_hash' => str_repeat('1', 64),
            'failed_criteria' => $failedCriteria,
        ];
    }

    /** @return array<string, mixed> */
    private function completionEvidence(): array
    {
        return [
            'completion_evidence_status_hash' => str_repeat('2', 64),
            'runtime_gap_matrix' => [
                'all_runtime_y' => false,
                'blocked_gap_ids' => ['adapter_execution_runtime'],
                'runtime_promotion_receipt' => [
                    'status' => 'blocked',
                    'receipt_hash' => '',
                ],
            ],
            'real_provider_smoke' => [
                'status' => 'blocked_missing_real_provider_smoke',
                'smoke_hash' => '',
                'violations' => [['code' => 'required_evidence_missing']],
            ],
            'human_signed_completion_receipt' => [
                'status' => 'blocked',
                'receipt_hash' => '',
                'violations' => [['code' => 'required_receipt_missing']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function blockerExplainer(): array
    {
        return [
            'explainer_hash' => str_repeat('3', 64),
            'command_plan' => [
                'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --json',
                'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --persist-runtime-promotion-receipt --json',
                'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json',
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --persist-completion-evidence --json',
                'compose_completion_evidence_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --json',
                'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --json',
                'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --persist-completion-evidence --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'completion_audit_with_terminal_loop_operational_proof' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function terminalLoopProofPayload(): array
    {
        return [
            'status' => 'passed',
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
            'completion_real_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'post_cycle_cycle_supervisor' => [
                'status' => 'cycle_evidence_review_ready',
                'cycle_state' => 'review_evidence',
                'next_command_purpose' => 'review_completed_dry_run_evidence_and_rerun_digest',
                'hash' => str_repeat('c', 64),
            ],
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => 0,
                'active_lease_count' => 0,
                'recoverable_lease_count' => 0,
            ],
            'post_cycle_end_to_end_contract' => [
                'status' => 'terminal_loop_end_to_end_contract_available',
                'all_required_surfaces_present' => true,
                'covered_capabilities' => [
                    'auto_replenishment',
                    'validation',
                    'leases',
                    'evidence',
                    'retomada',
                    'lane_isolation',
                    'cycle_supervision',
                    'operator_handoff',
                ],
                'failed_check_ids' => [],
                'hash' => str_repeat('7', 64),
            ],
            'terminal_loop_operational_proof_hash' => str_repeat('b', 64),
        ];
    }
}
