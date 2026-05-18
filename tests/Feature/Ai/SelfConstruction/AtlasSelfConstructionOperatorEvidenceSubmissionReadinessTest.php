<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceSubmissionReadinessTest extends TestCase
{
    public function test_no_input_reports_runtime_promotion_receipt_as_next_required(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.operator_evidence_submission_readiness.v1', $payload['schema_version']);
        $this->assertSame('read_only_operator_evidence_submission_readiness', $payload['mode']);
        $this->assertSame('no_input', $payload['status']);
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $payload['next_required_command']);
        $this->assertSame('atlas.self_construction.operator_evidence_submission_readiness_next_action.v1', data_get($payload, 'operator_next_action.schema_version'));
        $this->assertSame('blocked_operator_action_required', data_get($payload, 'operator_next_action.status'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_next_action.next_required'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_next_action.next_artifact'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_next_action.action_artifact'));
        $this->assertSame('next_required_verifier', data_get($payload, 'operator_next_action.action_source'));
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', data_get($payload, 'operator_next_action.exact_command'));
        $this->assertSame('', data_get($payload, 'operator_next_action.exact_persist_command'));
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', data_get($payload, 'operator_next_action.expected_persist_command_template'));
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-endgame-status', data_get($payload, 'operator_next_action.expected_endgame_command_template'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action.persist_command_template_available'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action.command_contains_placeholders'));
        $this->assertContains('<operator>', data_get($payload, 'operator_next_action.placeholder_fields_to_replace'));
        $this->assertFalse((bool) data_get($payload, 'operator_next_action.can_run_automatically'));
        $this->assertFalse((bool) data_get($payload, 'operator_next_action.can_persist_from_readiness'));
        $this->assertSame('requires_operator_signature_and_runtime_promotion_judgment', data_get($payload, 'operator_next_action.why_not_automatic'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_next_action.operator_next_action_hash'));
        $this->assertSame('atlas.self_construction.operator_evidence_submission_readiness_next_action_shell_packet.v1', data_get($payload, 'operator_next_action.shell_packet.schema_version'));
        $this->assertSame('blocked_placeholder_replacement_required', data_get($payload, 'operator_next_action.shell_packet.status'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_next_action.shell_packet.next_required'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_next_action.shell_packet.action_artifact'));
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', data_get($payload, 'operator_next_action.shell_packet.command_to_copy'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_next_action.shell_packet.command_to_copy_hash'));
        $this->assertContains('<operator>', data_get($payload, 'operator_next_action.shell_packet.placeholders'));
        $this->assertFalse((bool) data_get($payload, 'operator_next_action.shell_packet.copy_safe'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action.shell_packet.requires_fresh_readiness_status_before_copy'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action.shell_packet.can_resume_without_chat_history'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_next_action.shell_packet.shell_packet_hash'));
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertFalse($payload['runtime_promotion_receipt_passed']);
        $this->assertFalse($payload['real_provider_smoke_passed']);
        $this->assertFalse($payload['human_completion_receipt_passed']);
        $this->assertFalse($payload['human_receipt_out_of_order']);
        $this->assertSame(
            'blocked_until_required_operator_submission_envelopes_are_ready',
            data_get($payload, 'operator_submission_envelopes.status'),
        );
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_submission_envelopes.next_required_envelope'));
        $this->assertSame(3, data_get($payload, 'operator_submission_envelopes.required_envelope_count'));
        $this->assertSame(0, data_get($payload, 'operator_submission_envelopes.ready_envelope_count'));
        $this->assertSame(
            'blocked_until_operator_runtime_promotion_receipt_exists',
            data_get($payload, 'operator_submission_envelopes.runtime_promotion_receipt.status'),
        );
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.can_persist_from_readiness'));
        $this->assertSame('no_canonical_submission_files_loaded', data_get($payload, 'canonical_submission_persistence_plan.status'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.can_persist_from_readiness'));
        $this->assertSame(
            'atlas.self_construction.operator_evidence_sequence_integrity.v1',
            data_get($payload, 'operator_evidence_sequence_integrity.schema_version'),
        );
        $this->assertSame('sequence_integrity_ok', data_get($payload, 'operator_evidence_sequence_integrity.status'));
        $this->assertTrue(data_get($payload, 'operator_evidence_sequence_integrity.sequence_valid'));
        $this->assertSame(0, data_get($payload, 'operator_evidence_sequence_integrity.sequence_violation_count'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_evidence_sequence_integrity.current_required_artifact'));
        $this->assertSame(0, data_get($payload, 'operator_evidence_sequence_integrity.current_step_index'));
        $this->assertFalse(data_get($payload, 'operator_evidence_sequence_integrity.can_skip_steps'));
        $this->assertFalse(data_get($payload, 'operator_evidence_sequence_integrity.parallel_submission_allowed'));
        $this->assertFalse(data_get($payload, 'operator_evidence_sequence_integrity.can_persist_from_sequence_integrity'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_evidence_sequence_integrity.sequence_integrity_hash'));
        $this->assertSame(
            'atlas.self_construction.operator_completion_proof_bundle.v1',
            data_get($payload, 'operator_completion_proof_bundle.schema_version'),
        );
        $this->assertSame('blocked_missing_operator_proofs', data_get($payload, 'operator_completion_proof_bundle.status'));
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($payload, 'operator_completion_proof_bundle.missing_proofs'),
        );
        $this->assertSame(3, data_get($payload, 'operator_completion_proof_bundle.missing_proof_count'));
        $this->assertFalse(data_get($payload, 'operator_completion_proof_bundle.ready_for_final_completion_audit'));
        $this->assertStringContainsString(
            '--persist-terminal-loop-operational-proof-binding',
            (string) data_get($payload, 'operator_completion_proof_bundle.proof_commands.persist_terminal_loop_operational_proof_binding'),
        );
        $this->assertStringContainsString(
            '--persist-terminal-loop-operational-proof-binding',
            (string) data_get($payload, 'operator_evidence_closure_runbook.terminal_loop_operational_proof_binding_persist_command'),
        );
        $this->assertFalse(data_get($payload, 'operator_completion_proof_bundle.can_persist_from_proof_bundle'));
        $this->assertFalse(data_get($payload, 'operator_completion_proof_bundle.can_promote_completion_from_proof_bundle'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_completion_proof_bundle.proof_commands.completion_audit'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-os-completion-audit-status',
            data_get($payload, 'operator_completion_proof_bundle.proof_commands.completion_audit_diagnostic'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-status',
            data_get($payload, 'operator_completion_proof_bundle.proof_commands.terminal_loop_operational_proof'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_completion_proof_bundle.proof_commands.completion_audit_with_terminal_loop_operational_proof'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_completion_proof_bundle.proof_commands.completion_audit_with_canonical_terminal_loop_operational_proof'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_completion_proof_bundle.proof_commands.effective_completion_audit_with_terminal_loop_operational_proof'),
        );
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_completion_proof_bundle.terminal_loop_operational_proof_canonical_binding_path'),
        );
        $this->assertTrue((bool) data_get($payload, 'operator_completion_proof_bundle.terminal_loop_operational_proof_required_before_final_audit'));
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            data_get($payload, 'operator_completion_proof_bundle.terminal_loop_operational_proof_expected_binding_schema'),
        );
        $this->assertContains(
            'post_cycle_cycle_supervisor_review_evidence',
            data_get($payload, 'operator_completion_proof_bundle.terminal_loop_operational_proof_acceptance_criteria'),
        );
        $this->assertContains(
            'post_cycle_end_to_end_contract_available',
            data_get($payload, 'operator_completion_proof_bundle.terminal_loop_operational_proof_acceptance_criteria'),
        );
        $this->assertContains(
            'post_cycle_end_to_end_contract_covers_required_loop_surfaces',
            data_get($payload, 'operator_completion_proof_bundle.terminal_loop_operational_proof_acceptance_criteria'),
        );
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
            data_get($payload, 'operator_completion_proof_bundle.terminal_loop_operational_proof_required_end_to_end_contract_capabilities'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'operator_completion_proof_bundle.operator_completion_proof_bundle_hash'),
        );
        $this->assertSame(
            'atlas.self_construction.operator_evidence_closure_runbook.v1',
            data_get($payload, 'operator_evidence_closure_runbook.schema_version'),
        );
        $this->assertContains(
            data_get($payload, 'operator_evidence_closure_runbook.status'),
            ['operator_action_required', 'blocked_terminal_loop_not_ready'],
        );
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_evidence_closure_runbook.current_operator_step_id'));
        $this->assertGreaterThan(0, data_get($payload, 'operator_evidence_closure_runbook.failed_criteria_count'));
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($payload, 'operator_evidence_closure_runbook.missing_operator_proofs'),
        );
        $this->assertSame(3, data_get($payload, 'operator_evidence_closure_runbook.missing_operator_proof_count'));
        $this->assertSame(5, data_get($payload, 'operator_evidence_closure_runbook.ordered_step_count'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-status',
            data_get($payload, 'operator_evidence_closure_runbook.terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_evidence_closure_runbook.completion_audit_with_terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_evidence_closure_runbook.completion_audit_with_canonical_terminal_loop_operational_proof_command'),
        );
        $this->assertTrue((bool) data_get($payload, 'operator_evidence_closure_runbook.terminal_loop_operational_proof_required_before_final_audit'));
        $this->assertFalse(data_get($payload, 'operator_evidence_closure_runbook.can_execute_from_runbook'));
        $this->assertFalse(data_get($payload, 'operator_evidence_closure_runbook.can_persist_from_runbook'));
        $this->assertFalse(data_get($payload, 'operator_evidence_closure_runbook.can_sign_from_runbook'));
        $this->assertFalse(data_get($payload, 'operator_evidence_closure_runbook.can_call_provider_from_runbook'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'operator_evidence_closure_runbook.operator_evidence_closure_runbook_hash'),
        );
        $this->assertSame(
            'atlas.self_construction.operator_command_surface_integrity.v1',
            data_get($payload, 'operator_command_surface_integrity.schema_version'),
        );
        $this->assertSame(
            'command_surface_aligned',
            data_get($payload, 'operator_command_surface_integrity.status'),
        );
        $this->assertSame(0, data_get($payload, 'operator_command_surface_integrity.missing_option_count'));
        $this->assertTrue(data_get($payload, 'operator_command_surface_integrity.legacy_alias_free'));
        $this->assertSame(0, data_get($payload, 'operator_command_surface_integrity.legacy_alias_count'));
        $this->assertSame([], data_get($payload, 'operator_command_surface_integrity.legacy_aliases_detected'));
        $this->assertGreaterThan(0, data_get($payload, 'operator_command_surface_integrity.command_count'));
        $this->assertFalse(data_get($payload, 'operator_command_surface_integrity.can_execute_commands_from_integrity_check'));
        $this->assertFalse(data_get($payload, 'operator_command_surface_integrity.can_persist_from_integrity_check'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'operator_command_surface_integrity.command_surface_integrity_hash'),
        );
        $this->assertSame(4, data_get($payload, 'closure_artifact_sequence_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'closure_artifact_sequence_hash'));
        $this->assertSame(4, data_get($payload, 'prompt_to_artifact_checklist_count'));
        $this->assertSame(0, data_get($payload, 'prompt_to_artifact_checklist_passed_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'prompt_to_artifact_checklist_hash'));
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get(collect((array) data_get($payload, 'closure_artifact_sequence'))->keyBy('requirement')->all(), 'runtime_gap_matrix_all_runtime_y.artifact'),
        );
        $this->assertSame(
            'real_provider_smoke',
            data_get(collect((array) data_get($payload, 'prompt_to_artifact_checklist'))->keyBy('requirement')->all(), 'end_to_end_real_provider_smoke_green.artifact'),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_submission_envelopes.operator_submission_envelopes_hash'));
        $this->assertNotEmpty($payload['submission_readiness_hash']);
    }

    public function test_invalid_runtime_receipt_keeps_next_required_at_runtime_promotion_with_violations(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'rp-invalid',
                'signed_by' => '<operator>',
                'reason' => 'short',
                'runtime_gap_matrix_hash' => str_repeat('0', 64),
                'runtime_promotion_basis_hash' => str_repeat('0', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('0', 64),
                'promoted_gap_ids' => [],
                'graduation_evidence_hashes' => [],
                'receipt_hash' => str_repeat('0', 64),
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
            ],
        ]);

        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertFalse($payload['runtime_promotion_receipt_passed']);
        $this->assertGreaterThan(0, (int) data_get($payload, 'diagnostics.runtime_promotion_receipt.violation_count', 0));
        $this->assertTrue(data_get($payload, 'diagnostics.runtime_promotion_receipt.supplied'));
        $this->assertFalse(data_get($payload, 'diagnostics.runtime_promotion_receipt.ready'));
    }

    public function test_invalid_real_provider_smoke_detects_missing_provider_cost_and_observation_flags(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'real_provider_smoke' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'status' => 'passed',
                'provider_run_id' => '<missing>',
                'task_packet_id' => '<missing>',
                'observed_by' => '<missing>',
                'approval_reason' => '<missing>',
                'smoke_hash' => str_repeat('0', 64),
                'operator_approval_receipt_hash' => '<missing>',
                'evidence_ledger_hash' => '<missing>',
                'work_product_manifest_hash' => '<missing>',
                'cost_event_hash' => '<missing>',
                'continuation_summary_hash' => '<missing>',
                'provider_response_hash' => '<missing>',
                'provider_call_observed' => false,
                'token_spend_observed' => false,
                'claim_to_completion_observed' => false,
                'work_product_collected' => false,
                'operator_supplied_evidence' => false,
                'real_provider_run_observed_by_operator' => false,
            ],
        ]);

        $errors = (array) data_get($payload, 'diagnostics.real_provider_smoke.errors', []);
        $this->assertContains('missing_or_placeholder_provider_run_id', $errors);
        $this->assertContains('missing_or_placeholder_cost_event_hash', $errors);
        $this->assertContains('missing_or_placeholder_work_product_manifest_hash', $errors);
        $this->assertContains('missing_or_placeholder_continuation_summary_hash', $errors);
        $this->assertContains('missing_observation_flag_provider_call_observed', $errors);
        $this->assertContains('missing_observation_flag_token_spend_observed', $errors);
        $this->assertContains('missing_observation_flag_work_product_collected', $errors);
        $this->assertFalse($payload['real_provider_smoke_passed']);
        $this->assertSame(
            'blocked_until_real_provider_smoke_verifier_passes',
            data_get($payload, 'operator_submission_envelopes.real_provider_smoke.status'),
        );
        $this->assertSame(
            'real_provider_smoke',
            data_get($payload, 'operator_submission_envelopes.real_provider_smoke.artifact'),
        );
        $this->assertStringContainsString(
            '--real-provider-smoke-json=@/path/to/real-provider-smoke.json',
            (string) data_get($payload, 'operator_submission_envelopes.real_provider_smoke.persist_command'),
        );
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.real_provider_smoke.ready_for_explicit_operator_persistence'));
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.real_provider_smoke.can_persist_from_readiness'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_submission_envelopes.real_provider_smoke.operator_submission_envelope_hash'));
    }

    public function test_human_receipt_supplied_before_runtime_and_smoke_is_marked_out_of_order(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'completion_receipt' => [
                'receipt_id' => 'human-early',
                'signed_by' => 'operator-name-real',
                'reason' => 'Operator reviewed everything as if it was green.',
                'completion_audit_hash' => str_repeat('1', 64),
                'release_dossier_hash' => str_repeat('1', 64),
                'replay_diff_hash' => str_repeat('1', 64),
                'runtime_gap_matrix_hash' => str_repeat('1', 64),
                'runtime_promotion_receipt_hash' => str_repeat('1', 64),
                'real_provider_smoke_hash' => str_repeat('1', 64),
                'certification_status_batch_hash' => str_repeat('1', 64),
                'receipt_hash' => str_repeat('1', 64),
                'os_complete_approved' => true,
                'operator_reviewed_completion_audit' => true,
                'no_autopromotion_acknowledged' => true,
            ],
        ]);

        $this->assertTrue($payload['human_receipt_out_of_order']);
        $this->assertFalse(data_get($payload, 'diagnostics.human_completion_receipt.ready'));
        $this->assertContains(
            'human_completion_receipt_supplied_before_runtime_and_smoke_green',
            (array) data_get($payload, 'diagnostics.human_completion_receipt.errors', []),
        );
        $this->assertSame(
            'blocked_until_runtime_and_smoke_envelopes_are_green',
            data_get($payload, 'operator_submission_envelopes.human_completion_receipt.status'),
        );
        $this->assertSame('sequence_integrity_blocked', data_get($payload, 'operator_evidence_sequence_integrity.status'));
        $this->assertFalse(data_get($payload, 'operator_evidence_sequence_integrity.sequence_valid'));
        $this->assertContains(
            'human_completion_receipt_supplied_before_previous_artifacts_green',
            data_get($payload, 'operator_evidence_sequence_integrity.sequence_violations'),
        );
        $humanState = collect(data_get($payload, 'operator_evidence_sequence_integrity.artifact_states'))->firstWhere('artifact', 'human_completion_receipt');
        $this->assertTrue(data_get($humanState, 'out_of_order_submission_detected'));
        $this->assertFalse(data_get($humanState, 'previous_artifacts_green'));
        $this->assertSame(
            'completion_receipt',
            data_get($payload, 'operator_submission_envelopes.human_completion_receipt.source_option_key'),
        );
        $this->assertIsArray(data_get($payload, 'operator_submission_envelopes.human_completion_receipt.current_evidence_context'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'operator_submission_envelopes.human_completion_receipt.current_evidence_context.completion_audit_hash'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'operator_submission_envelopes.human_completion_receipt.current_evidence_context.release_dossier_hash'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'operator_submission_envelopes.human_completion_receipt.current_evidence_context.replay_diff_hash'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($payload, 'operator_submission_envelopes.human_completion_receipt.current_evidence_context.certification_status_batch_hash'),
        );
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
    }

    public function test_forbidden_flags_in_real_provider_smoke_payload_are_flagged(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'real_provider_smoke' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'status' => 'passed',
                'provider_run_id' => 'pr-1',
                'task_packet_id' => 'tp-1',
                'observed_by' => 'operator-real',
                'approval_reason' => 'Operator approved this and observed it.',
                'smoke_hash' => str_repeat('0', 64),
                'operator_approval_receipt_hash' => str_repeat('0', 64),
                'evidence_ledger_hash' => str_repeat('0', 64),
                'work_product_manifest_hash' => str_repeat('0', 64),
                'cost_event_hash' => str_repeat('0', 64),
                'continuation_summary_hash' => str_repeat('0', 64),
                'provider_response_hash' => str_repeat('0', 64),
                'provider_call_observed' => true,
                'token_spend_observed' => true,
                'claim_to_completion_observed' => true,
                'work_product_collected' => true,
                'operator_supplied_evidence' => true,
                'real_provider_run_observed_by_operator' => true,
                'dispatch_allowed' => true,
                'self_programming_allowed' => true,
                'completion_claim_promoted_without_receipt' => true,
            ],
        ]);

        $flagsTrue = (array) data_get($payload, 'diagnostics.real_provider_smoke.forbidden_flags_true', []);
        $this->assertContains('dispatch_allowed', $flagsTrue);
        $this->assertContains('self_programming_allowed', $flagsTrue);
        $this->assertContains('completion_claim_promoted_without_receipt', $flagsTrue);
    }

    public function test_stale_runtime_context_hashes_are_detected_when_receipt_drifted(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'rp-stale',
                'signed_by' => 'operator-real',
                'reason' => 'Operator reviewed and signed under stale hashes.',
                'runtime_gap_matrix_hash' => str_repeat('f', 64),
                'runtime_promotion_basis_hash' => str_repeat('e', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('d', 64),
                'promoted_gap_ids' => [],
                'graduation_evidence_hashes' => [],
                'receipt_hash' => str_repeat('c', 64),
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
            ],
        ]);

        $stale = (array) $payload['stale_context_hashes'];
        $this->assertContains('runtime_gap_matrix_hash', $stale);
        $this->assertContains('runtime_promotion_basis_hash', $stale);
        $this->assertContains('runtime_promotion_closure_basis_hash', $stale);
    }

    public function test_submission_readiness_can_load_operator_draft_workspace_without_persisting(): void
    {
        Storage::fake('local');
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness();

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('loaded_for_read_only_submission_readiness', data_get($payload, 'draft_workspace_input.status'));
        $this->assertSame('workspace_safe_for_operator_editing', data_get($payload, 'draft_workspace_input.inspector_status'));
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], data_get($payload, 'draft_workspace_input.loaded_artifacts'));
        $this->assertTrue(data_get($payload, 'diagnostics.runtime_promotion_receipt.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.real_provider_smoke.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.human_completion_receipt.supplied'));
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.can_persist_from_readiness'));
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);
        $this->assertSame('blocked_operator_drafts_not_ready_for_hash_write', data_get($payload, 'draft_hash_finalization.status'));
        $this->assertSame(3, data_get($payload, 'draft_hash_finalization.artifact_count'));
        $this->assertStringContainsString('--atlas-self-construction-operator-evidence-draft-hash-finalizer-status', data_get($payload, 'draft_hash_finalization.write_command'));
        $this->assertFalse($payload['draft_hash_finalization_required']);
    }

    public function test_submission_readiness_accepts_private_storage_prefixed_operator_draft_workspace_path(): void
    {
        Storage::fake('local');
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness();

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => 'storage/app/private/'.$workspace,
        ]);

        $this->assertSame('loaded_for_read_only_submission_readiness', data_get($payload, 'draft_workspace_input.status'));
        $this->assertSame($workspace.'/manifest.json', data_get($payload, 'draft_workspace_input.manifest_path'));
        $this->assertSame($workspace, data_get($payload, 'draft_workspace_input.workspace_directory'));
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], data_get($payload, 'draft_workspace_input.loaded_artifacts'));
        $this->assertTrue(data_get($payload, 'draft_workspace_input.workspace_safe_for_operator_editing'));
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
    }

    public function test_submission_readiness_auto_loads_canonical_published_submission_files_without_persisting(): void
    {
        Storage::fake('local');
        $this->writeCanonicalSubmissionFilesForReadiness();

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('loaded_for_read_only_submission_readiness', data_get($payload, 'canonical_submission_input.status'));
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($payload, 'canonical_submission_input.loaded_artifacts'),
        );
        $this->assertTrue(data_get($payload, 'diagnostics.runtime_promotion_receipt.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.real_provider_smoke.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.human_completion_receipt.supplied'));
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertFalse(data_get($payload, 'canonical_submission_input.published_submission_json_is_evidence'));
        $this->assertFalse(data_get($payload, 'canonical_submission_input.can_persist_canonical_submission_files_directly'));
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.can_persist_from_readiness'));
        $this->assertSame(
            'blocked_until_canonical_submission_files_are_ready',
            data_get($payload, 'canonical_submission_persistence_plan.status'),
        );
        $this->assertTrue(data_get($payload, 'canonical_submission_persistence_plan.canonical_source_authoritative'));
        $this->assertTrue(data_get($payload, 'canonical_submission_persistence_plan.sequence_ordered'));
        $this->assertTrue(data_get($payload, 'canonical_submission_persistence_plan.requires_explicit_operator_persistence_commands'));
        $this->assertTrue(data_get($payload, 'canonical_submission_persistence_plan.human_receipt_persistence_requires_prior_persisted_smoke_command'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.can_persist_from_readiness'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.persisted_evidence_state.runtime_promotion_receipt.persisted_green'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.persisted_evidence_state.real_provider_smoke.persisted_green'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.persisted_evidence_state.human_completion_receipt.persisted_green'));
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions',
            data_get($payload, 'canonical_submission_persistence_plan.canonical_submission_private_storage_directory'),
        );
        $this->assertSame(
            [
                'persist_runtime_promotion_receipt',
                'persist_real_provider_smoke',
                'persist_human_completion_receipt',
                'rerun_completion_audit',
            ],
            array_column(data_get($payload, 'canonical_submission_persistence_plan.steps'), 'id'),
        );
        $this->assertStringContainsString(
            '--runtime-promotion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.0.command'),
        );
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.0.canonical_submission_private_storage_path'),
        );
        $this->assertStringContainsString(
            '--real-provider-smoke-json=@storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.1.command'),
        );
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.1.canonical_submission_private_storage_path'),
        );
        $this->assertStringContainsString(
            '--completion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.2.command'),
        );
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.2.canonical_submission_private_storage_path'),
        );
        $this->assertSame(
            'canonical_submission_verifier_not_ready',
            data_get($payload, 'canonical_submission_persistence_plan.steps.0.blocker'),
        );
        $this->assertSame(
            'runtime_promotion_receipt_must_be_persisted_first',
            data_get($payload, 'canonical_submission_persistence_plan.steps.1.blocker'),
        );
        $this->assertSame(
            'runtime_promotion_and_real_provider_smoke_must_be_persisted_first',
            data_get($payload, 'canonical_submission_persistence_plan.steps.2.blocker'),
        );
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.steps.0.persisted_evidence_already_green'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.steps.1.persisted_evidence_already_green'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.steps.2.persisted_evidence_already_green'));
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus();
        $this->assertSame(
            'loaded_for_read_only_submission_readiness',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_input_status'),
        );
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_loaded_artifacts'),
        );
        $this->assertSame(
            'blocked_until_canonical_submission_files_are_ready',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_status'),
        );
        $this->assertSame(
            'persist_runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_next_step_id'),
        );
        $this->assertSame(
            4,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_step_count'),
        );
        $this->assertTrue(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_sequence_ordered'));
        $this->assertFalse(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_runtime_promotion_receipt_persisted_green'));
        $this->assertFalse(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_real_provider_smoke_persisted_green'));
        $this->assertFalse(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_human_completion_receipt_persisted_green'));
        $this->assertTrue(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_human_receipt_requires_prior_persisted_smoke_command'));
        $this->assertFalse(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_can_persist_from_readiness'));
    }

    public function test_operator_next_action_points_to_ready_canonical_persistence_step_before_advancing_to_next_artifact(): void
    {
        $service = new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class));
        $canonicalPlan = $this->invokeSubmissionReadinessPrivate($service, 'canonicalSubmissionPersistencePlan', [
            ['loaded_artifacts' => ['runtime_promotion_receipt']],
            [
                'runtime_promotion_receipt' => ['ready' => true, 'status' => 'passed', 'errors' => []],
                'real_provider_smoke' => ['ready' => false, 'status' => 'not_supplied', 'errors' => []],
                'human_completion_receipt' => ['ready' => false, 'status' => 'not_supplied', 'errors' => []],
            ],
            false,
            false,
            [
                'runtime_promotion_receipt' => ['persisted_green' => false],
                'real_provider_smoke' => ['persisted_green' => false],
                'human_completion_receipt' => ['persisted_green' => false],
            ],
        ]);
        $operatorNextAction = $this->invokeSubmissionReadinessPrivate($service, 'operatorNextAction', [
            'real_provider_smoke',
            'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            [
                'real_provider_smoke' => [
                    'status' => 'blocked_until_real_provider_smoke_verifier_passes',
                    'ready_for_explicit_operator_persistence' => false,
                    'errors' => [],
                ],
            ],
            $canonicalPlan,
        ]);

        $this->assertSame(
            'ready_for_next_explicit_operator_persistence_step',
            data_get($canonicalPlan, 'status'),
        );
        $this->assertSame(
            'persist_runtime_promotion_receipt',
            data_get($canonicalPlan, 'next_step_id'),
        );
        $this->assertSame(
            'ready_for_explicit_operator_persistence',
            data_get($canonicalPlan, 'steps.0.status'),
        );
        $this->assertSame(
            'canonical_submission_persistence_plan',
            data_get($operatorNextAction, 'action_source'),
        );
        $this->assertSame(
            'persist_runtime_promotion_receipt',
            data_get($operatorNextAction, 'action_step_id'),
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($operatorNextAction, 'action_artifact'),
        );
        $this->assertTrue((bool) data_get($operatorNextAction, 'ready_for_explicit_operator_persistence'));
        $this->assertStringContainsString(
            '--runtime-promotion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            (string) data_get($operatorNextAction, 'exact_command'),
        );
        $this->assertSame(
            data_get($operatorNextAction, 'exact_command'),
            data_get($operatorNextAction, 'exact_persist_command'),
        );
        $this->assertSame(
            data_get($operatorNextAction, 'exact_command'),
            data_get($operatorNextAction, 'expected_persist_command_template'),
        );
        $this->assertSame('', data_get($operatorNextAction, 'expected_endgame_command_template'));
        $this->assertFalse((bool) data_get($operatorNextAction, 'can_persist_from_readiness'));
    }

    public function test_submission_readiness_reports_when_workspace_hash_finalization_is_required(): void
    {
        Storage::fake('local');
        $runtimeMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'runtime-test',
                'signed_by' => 'operator-real',
                'reason' => 'Operator reviewed runtime promotion candidates and approves without enabling execution.',
                'runtime_gap_matrix_hash' => (string) data_get($runtimeMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt'),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeMatrix, 'runtime_promotion_basis_hash'),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeMatrix, 'runtime_promotion_closure_basis_hash'),
                'promoted_gap_ids' => (array) data_get($runtimeMatrix, 'blocked_gap_ids', []),
                'graduation_evidence_hashes' => [],
                'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ]);

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertTrue($payload['draft_hash_finalization_required']);
        $this->assertTrue(data_get($payload, 'draft_hash_finalization.artifacts.runtime_promotion_receipt.can_write_hash_to_draft'));
        $this->assertFalse(data_get($payload, 'draft_hash_finalization.artifacts.runtime_promotion_receipt.input_hash_matches_computed_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'draft_hash_finalization.artifacts.runtime_promotion_receipt.computed_hash'));
        $this->assertStringContainsString('--write-computed-operator-draft-hashes', data_get($payload, 'draft_hash_finalization.write_command'));
        $this->assertFalse(data_get($payload, 'draft_hash_finalization.can_write_from_submission_readiness'));
    }

    public function test_submission_readiness_recommends_refresh_for_stale_draft_workspace_hashes(): void
    {
        Storage::fake('local');
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'runtime-test',
                'signed_by' => 'operator-real',
                'reason' => 'Operator reviewed and signed under stale hashes.',
                'runtime_gap_matrix_hash' => str_repeat('1', 64),
                'runtime_promotion_basis_hash' => str_repeat('2', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('3', 64),
                'receipt_hash' => '<hash>',
            ],
        ]);

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertTrue($payload['draft_workspace_refresh_required']);
        $this->assertSame(
            'refresh_recommended_before_operator_signature',
            data_get($payload, 'draft_workspace_refresh.status'),
        );
        $this->assertContains(
            'runtime_promotion_receipt_context_hashes_are_stale',
            data_get($payload, 'draft_workspace_refresh.reasons'),
        );
        $this->assertContains('runtime_gap_matrix_hash', data_get($payload, 'draft_workspace_refresh.stale_context_hashes'));
        $this->assertStringContainsString(
            '--persist-operator-draft-workspace',
            (string) data_get($payload, 'draft_workspace_refresh.refresh_command'),
        );
        $this->assertFalse(data_get($payload, 'draft_workspace_refresh.can_refresh_from_readiness'));
        $this->assertFalse(data_get($payload, 'draft_workspace_refresh.can_persist_evidence_from_refresh'));
    }

    public function test_cli_submission_readiness_loads_operator_draft_workspace_path(): void
    {
        Storage::fake('local');
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness();

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-operator-evidence-submission-readiness-status' => true,
            '--operator-draft-workspace-path' => $workspace,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('loaded_for_read_only_submission_readiness', data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness.draft_workspace_input.status'));
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_loaded_artifacts'));
        $this->assertSame(3, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_artifact_count'));
        $this->assertTrue((bool) data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_safe_for_operator_editing'));
        $this->assertSame(0, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_violation_count'));
        $this->assertSame(0, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_warning_count'));
        $this->assertFalse((bool) data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_is_evidence'));
        $this->assertFalse((bool) data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_can_persist_draft_directly'));
        $this->assertTrue((bool) data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_workspace_payload_supplied'));
        $this->assertFalse((bool) data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_canonical_source_authoritative'));
        $this->assertSame(0, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_loaded_artifact_count'));
        $this->assertFalse(data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_refresh_required'));
        $this->assertSame(2, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_runtime_promotion_receipt_placeholder_count'));
        $this->assertContains('signed_by', data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_runtime_promotion_receipt_placeholders'));
        $this->assertSame(2, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_real_provider_smoke_placeholder_count'));
        $this->assertContains('provider_run_id', data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_real_provider_smoke_placeholders'));
        $this->assertSame(2, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_human_completion_receipt_placeholder_count'));
        $this->assertContains('signed_by', data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_human_completion_receipt_placeholders'));
        $this->assertSame(6, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_total_placeholder_count'));
        $this->assertGreaterThan(0, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_total_error_count'));
        $this->assertTrue((bool) data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_all_supplied'));
        $this->assertSame(0, data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_ready_count'));
        $this->assertFalse($decoded['dispatch_allowed']);
    }

    public function test_cli_submission_readiness_human_output_exposes_operator_closure_runbook(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-operator-evidence-submission-readiness-status' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();

        $this->assertStringContainsString('Proof bundle', $output);
        $this->assertStringContainsString('blocked_missing_operator_proofs', $output);
        $this->assertStringContainsString('Missing operator proofs:', $output);
        $this->assertStringContainsString('runtime_promotion_receipt', $output);
        $this->assertStringContainsString('real_provider_smoke', $output);
        $this->assertStringContainsString('human_completion_receipt', $output);
        $this->assertStringContainsString('Closure runbook', $output);
        $this->assertStringContainsString('Runbook current step', $output);
        $this->assertStringContainsString('Command surface', $output);
        $this->assertStringContainsString('command_surface_aligned', $output);
        $this->assertStringContainsString('Missing CLI options', $output);
        $this->assertStringContainsString('Closure artifact sequence:', $output);
        $this->assertStringContainsString('Terminal proof guardrails:', $output);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', $output);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', $output);
        $this->assertStringContainsString('Prompt-to-artifact checklist:', $output);
        $this->assertStringContainsString('Ready for final audit', $output);
        $this->assertStringContainsString('Terminal proof ready', $output);
    }

    public function test_readiness_status_and_cli_quartet_exist(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.v1', $status['schema_version']);
        $this->assertSame('no_input', $status['status']);
        $this->assertSame(
            'no_input',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.summary_status'),
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.next_required_submission'),
        );
        $this->assertSame(
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_ready_count'),
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.ready_artifact_count'),
        );
        $this->assertSame(
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_total_error_count'),
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.error_count'),
        );
        $this->assertSame(
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.diagnostic_total_placeholder_count'),
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.placeholder_count'),
        );
        $this->assertSame(
            'blocked_operator_action_required',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_status'),
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_next_required'),
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_action_artifact'),
        );
        $this->assertSame(
            'next_required_verifier',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_action_source'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_exact_command'),
        );
        $this->assertSame(
            '',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_exact_persist_command'),
        );
        $this->assertStringContainsString(
            '--persist-runtime-promotion-receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_expected_persist_command_template'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-endgame-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_expected_endgame_command_template'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_persist_command_template_available'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_ready_for_explicit_operator_persistence'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_can_persist_from_readiness'));
        $this->assertContains(
            '<operator>',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_placeholder_fields_to_replace'),
        );
        $this->assertSame(
            'requires_operator_signature_and_runtime_promotion_judgment',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_why_not_automatic'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_hash'),
        );
        $this->assertSame(
            'blocked_placeholder_replacement_required',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_shell_packet_status'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_shell_packet_hash'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_command_to_copy'),
        );
        $this->assertGreaterThanOrEqual(
            1,
            (int) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_shell_packet_placeholder_count'),
        );
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_shell_packet_copy_safe'));
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_next_action_shell_packet.next_required'),
        );
        $this->assertSame(
            'sequence_integrity_ok',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_integrity_status'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_valid'));
        $this->assertSame(0, data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_violation_count'));
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_current_required_artifact'),
        );
        $this->assertSame(0, data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_current_step_index'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_can_skip_steps'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_parallel_submission_allowed'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_sequence_hash'),
        );
        $this->assertSame(
            'blocked_missing_operator_proofs',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_proof_bundle_status'),
        );
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_missing_proofs'),
        );
        $this->assertSame(
            3,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_missing_proof_count'),
        );
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_ready_for_final_audit'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_can_persist_from_proof_bundle'));
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_terminal_loop_operational_proof_required_before_final_audit'));
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.terminal_loop_operational_proof_required_before_final_receipt'));
        $this->assertIsBool(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.terminal_loop_operational_proof_ready'));
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_terminal_loop_operational_proof_expected_binding_schema'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_audit_with_terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_audit_with_canonical_terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_effective_audit_with_terminal_loop_operational_proof_command'),
        );
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_terminal_loop_operational_proof_canonical_binding_path'),
        );
        $this->assertSame(
            7,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_terminal_loop_operational_proof_acceptance_criteria_count'),
        );
        $this->assertSame(
            8,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_terminal_loop_operational_proof_required_end_to_end_contract_capability_count'),
        );
        $this->assertContains(
            'operator_handoff',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_terminal_loop_operational_proof_required_end_to_end_contract_capabilities'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_completion_proof_bundle_hash'),
        );
        $this->assertContains(
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_status'),
            ['operator_action_required', 'blocked_terminal_loop_not_ready'],
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_current_step_id'),
        );
        $this->assertIsInt(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_technical_blocker_count'));
        $this->assertIsArray(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_technical_blockers'));
        $this->assertIsArray(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_failed_criteria'));
        $this->assertSame(
            3,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_missing_operator_proof_count'),
        );
        $this->assertSame(
            5,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_ordered_step_count'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_completion_audit_with_terminal_loop_operational_proof_command'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_terminal_loop_operational_proof_required_before_final_audit'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_can_execute'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_can_persist'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_evidence_closure_runbook_hash'),
        );
        $this->assertSame(
            'command_surface_aligned',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_command_surface_integrity_status'),
        );
        $this->assertGreaterThan(
            0,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_command_surface_integrity_command_count'),
        );
        $this->assertSame(
            0,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_command_surface_integrity_missing_option_count'),
        );
        $this->assertSame(
            0,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_command_surface_integrity_legacy_alias_count'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_command_surface_integrity_legacy_alias_free'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.operator_command_surface_integrity_hash'),
        );
        $this->assertFalse($status['execution_allowed']);
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.provider_call_allowed'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.token_spend_allowed'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.self_programming_allowed'));

        foreach ([
            'atlas-self-construction-operator-evidence-submission-readiness-contract',
            'atlas-self-construction-operator-evidence-submission-readiness-preflight',
            'atlas-self-construction-operator-evidence-submission-readiness-implementation-packet',
            'atlas-self-construction-operator-evidence-submission-readiness-status',
        ] as $option) {
            $exit = Artisan::call('atlas:ai:self-construction', [
                '--'.$option => true,
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
            $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertFalse($decoded['dispatch_allowed']);
        }
    }

    public function test_agent_control_plane_lists_submission_readiness_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_operator_evidence_submission_readiness_contract',
            'atlas_self_construction_operator_evidence_submission_readiness_preflight',
            'atlas_self_construction_operator_evidence_submission_readiness_implementation_packet',
            'atlas_self_construction_operator_evidence_submission_readiness_service',
            'atlas_self_construction_operator_evidence_submission_readiness_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @param array<string, array<string, mixed>> $overrides */
    private function writeDraftWorkspaceForSubmissionReadiness(array $overrides = []): string
    {
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/20260515-010000-readiness';
        $artifacts = [
            'runtime_promotion_receipt' => ['receipt_id' => 'runtime-test', 'signed_by' => '<operator>', 'receipt_hash' => '<hash>'],
            'real_provider_smoke' => ['provider_run_id' => '<provider_run_id>', 'smoke_hash' => '<hash>'],
            'human_completion_receipt' => ['receipt_id' => 'completion-test', 'signed_by' => '<operator>', 'receipt_hash' => '<hash>'],
        ];
        foreach ($overrides as $artifact => $override) {
            $artifacts[$artifact] = array_merge($artifacts[$artifact] ?? [], $override);
        }
        $files = [];

        foreach ($artifacts as $artifact => $payload) {
            $filename = match ($artifact) {
                'runtime_promotion_receipt' => 'runtime-promotion.json',
                'real_provider_smoke' => 'real-provider-smoke.json',
                default => 'completion-receipt.json',
            };
            $path = $workspace.'/'.$filename;
            $json = $this->draftJson($payload);
            Storage::disk('local')->put($path, $json);
            $files[] = [
                'artifact' => $artifact,
                'draft_path' => $path,
                'payload_template_json_sha256' => hash('sha256', $json),
                'draft_file_sha256' => hash('sha256', $json),
                'command_to_verify_draft_file' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'draft_is_evidence' => false,
                'can_persist_draft_directly' => false,
            ];
        }

        Storage::disk('local')->put($workspace.'/manifest.json', $this->draftJson([
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'workspace_directory' => $workspace,
            'files' => $files,
        ]));

        return $workspace;
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function invokeSubmissionReadinessPrivate(AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService $service, string $method, array $arguments): array
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return (array) $reflection->invokeArgs($service, $arguments);
    }

    private function writeCanonicalSubmissionFilesForReadiness(): void
    {
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/runtime-promotion.json', $this->draftJson([
            'receipt_id' => 'runtime-canonical',
            'signed_by' => '<operator>',
            'receipt_hash' => '<hash>',
        ]));
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/real-provider-smoke.json', $this->draftJson([
            'provider_run_id' => '<provider_run_id>',
            'smoke_hash' => '<hash>',
        ]));
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/completion-receipt.json', $this->draftJson([
            'receipt_id' => 'completion-canonical',
            'signed_by' => '<operator>',
            'receipt_hash' => '<hash>',
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function draftJson(array $payload): string
    {
        ksort($payload);

        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
